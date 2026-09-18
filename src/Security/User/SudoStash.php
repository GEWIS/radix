<?php

declare(strict_types=1);

namespace App\Security\User;

use App\Entity\Application\Enums\StorageNamespace;
use App\Service\Application\FileStorage;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function bin2hex;
use function dirname;
use function fclose;
use function fopen;
use function hash_equals;
use function in_array;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function parse_url;
use function preg_match;
use function preg_quote;
use function random_bytes;
use function str_contains;
use function strlen;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_HOST;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

/**
 * Keeps a write that was refused for want of a sudo grant, so that confirming the password does not cost the user
 * what they had written.
 *
 * Keyed by an id of its own rather than by the session alone, because one account has several of these at once: two
 * tabs on one browser, and a phone and a desktop, each reach the prompt separately and each has to find its own
 * again. The id is part of the address of the prompt and of the submission back from it. The session and firewall
 * are part of the key as well
 * ({@see SudoSession}), and the account is checked on the way out, so a stash is unreachable from another browser
 * and from another account that is later given the same session.
 *
 * Nothing is kept for a request that did not come from this site. A refused request has not had its CSRF token
 * checked yet, because the grant is required before the controller runs, so a request that is only stashed because
 * an attacker's page caused the browser to make it would otherwise be re-run with the user's own confirmation behind
 * it.
 *
 * The credential fields are dropped. Everything else is kept as it was received, including the CSRF token, which the
 * replay is validated on like any other submission.
 */
final readonly class SudoStash
{
    private const string KEY_PREFIX = 'gws_sudo_stash_';

    /**
     * Field names that are never kept, matched on the whole name and, for a password, on any name containing it.
     * `_csrf_token` is deliberately absent: it is not a credential, and the replay is refused without it.
     */
    private const array CREDENTIAL_FIELDS = [
        'mfacode',
        'backupcode',
        'code',
        'totp',
        'secret',
        'token',
        'apikey',
        'privatekey',
    ];

    /** More than this in one request is a sign of something other than a form, and is not kept. */
    private const int MAX_BODY_BYTES = 256 * 1024;

    private const int MAX_FILES = 20;

    /** Across the uploads of one stash, so that being refused repeatedly cannot be used to fill the storage. */
    private const int MAX_TOTAL_BYTES = 64 * 1024 * 1024;

    /** What {@see stash()} generates, and the only shape any other method acts on. */
    private const string ID_BODY = '[0-9a-f]{32}';

    private const string ID_PATTERN = '/^' . self::ID_BODY . '$/';

    public function __construct(
        private SudoSession $session,
        private SudoArea $area,
        private ReplayableAction $replayable,
        private FileStorage $fileStorage,
        private LoggerInterface $logger,
        #[Autowire(service: 'Redis')]
        private Redis $redis,
        #[Autowire(param: 'app.sudo_stash_ttl')]
        private int $ttlSeconds,
        #[Autowire(param: 'form.type_extension.csrf.field_name')]
        private string $csrfFieldName = '_csrf_token',
    ) {
    }

    /**
     * Keeps the request and returns the id to find it again by, or null where there is nothing worth keeping.
     */
    public function stash(Request $request): ?string
    {
        if ($request->isMethodSafe()) {
            return null;
        }

        // A heartbeat has no body worth keeping, and one arrives every interval for as long as the tab is open.
        if ($this->area->isKeepalive($request)) {
            return null;
        }

        if (!$this->cameFromThisSite($request)) {
            return null;
        }

        $identifier = $this->session->userIdentifier();
        $suffix = $this->session->current();
        if (
            null === $identifier
            || null === $suffix
        ) {
            return null;
        }

        $id = bin2hex(random_bytes(16));
        $key = $this->key(
            $suffix,
            $id,
        );

        /** @var array<array-key, mixed> $parameters */
        $parameters = $this->withoutCredentials($request->request->all());

        $body = json_encode(
            [
                'identifier' => $identifier,
                'method' => $request->getMethod(),
                'uri' => $request->getRequestUri(),
                'parameters' => $parameters,
                // The uploads are read back only where the write is sent again, so a refusal of any other action
                // keeps the fields and none of the bytes ({@see ReplayableAction}).
                'files' => $this->replayable->covers($request)
                    ? $this->keepUploads(
                        $request->files->all(),
                        $id,
                    )
                    : [],
            ],
            JSON_THROW_ON_ERROR,
        );

        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->discardUploads($id);

            return null;
        }

        $this->redis->setex(
            $key,
            $this->ttlSeconds,
            $body,
        );

        return $id;
    }

    public function read(string $id): ?StashedWrite
    {
        $key = $this->keyFor($id);
        if (null === $key) {
            return null;
        }

        $body = $this->redis->get($key);
        if (!is_string($body)) {
            return null;
        }

        /** @var mixed $stashed */
        $stashed = json_decode(
            $body,
            true,
        );
        if (!is_array($stashed)) {
            return null;
        }

        $identifier = $this->session->userIdentifier();
        if (
            null === $identifier
            || !is_string($stashed['identifier'] ?? null)
            || !hash_equals(
                $stashed['identifier'],
                $identifier,
            )
        ) {
            return null;
        }

        if (
            !is_string($stashed['method'] ?? null)
            || !is_string($stashed['uri'] ?? null)
            || !is_array($stashed['parameters'] ?? null)
            || !is_array($stashed['files'] ?? null)
        ) {
            return null;
        }

        return new StashedWrite(
            $stashed['method'],
            $stashed['uri'],
            $stashed['parameters'],
            $stashed['files'],
        );
    }

    /**
     * Where an upload of this stash was written.
     *
     * Derived here rather than kept in the record, so that what is read back cannot address a file outside the
     * stash it belongs to. The id is checked against the shape {@see stash()} generates, which is what keeps a
     * directory of another namespace out of the path.
     */
    public function uploadPath(
        string $id,
        int $index,
    ): ?string {
        if (
            !$this->isWellFormed($id)
            || $index < 0
        ) {
            return null;
        }

        return StorageNamespace::SudoStash->directory($id) . '/' . $index;
    }

    /**
     * Removes a stash once it has been acted on. The key expires on its own; the uploads do not, which is why
     * {@see prune()} exists for the ones that are never collected.
     */
    public function discard(string $id): void
    {
        $key = $this->keyFor($id);
        if (null !== $key) {
            $this->redis->del($key);
        }

        $this->discardUploads($id);
    }

    /**
     * Throws away the uploads of every stash last written before $before, and returns how many stashes that was.
     *
     * Here rather than in {@see \App\Command\User\PruneSudoStashesCommand} because the layout being swept is the one
     * {@see uploadPath()} writes: the id shape and the position under it are stated once. A caller that restated them
     * would match nothing, and report success, the first time either changed.
     *
     * @return int the number of stashes thrown away
     */
    public function prune(DateTimeImmutable $before): int
    {
        $root = $this->uploadRoot();
        // Only what this class writes. Deriving the directory from the path alone would throw away every stash at
        // once for a file that sits directly under the root, which is not a shape anything writes but is one the
        // sweep must not act on.
        $upload = '{^' . preg_quote(
            $root,
            '{',
        ) . '/(' . self::ID_BODY . ')/\d+$}';

        /** @var array<string, int> $newest */
        $newest = [];
        foreach (
            $this->fileStorage->listFiles(
                $root,
                true,
            ) as $path
        ) {
            if (
                1 !== preg_match(
                    $upload,
                    $path,
                    $matches,
                )
            ) {
                continue;
            }

            $directory = $root . '/' . $matches[1];

            try {
                $modified = $this->fileStorage->lastModified($path);
            } catch (Throwable) {
                // Collected while the listing was being walked, which is a stash the sweep no longer has to
                // account for. Reading on would end the run and leave every stash after this one where it is.
                continue;
            }

            $newest[$directory] = max(
                $newest[$directory] ?? 0,
                $modified,
            );
        }

        $pruned = 0;
        foreach ($newest as $directory => $modified) {
            if ($modified > $before->getTimestamp()) {
                continue;
            }

            $this->fileStorage->deleteDirectory($directory);
            ++$pruned;
        }

        return $pruned;
    }

    /**
     * Whether the browser states that it made this request from a page of this site.
     *
     * Deliberately stricter than {@see \Symfony\Component\Security\Csrf\SameOriginCsrfTokenManager}, which treats an
     * absent statement as undecided and falls back to the double-submit cookie. There is no token to fall back to at
     * the point a request is refused, so an absent statement means nothing is kept.
     */
    private function cameFromThisSite(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site) {
            return 'same-origin' === $site;
        }

        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');
        if (null === $origin) {
            return false;
        }

        $port = parse_url(
            $origin,
            PHP_URL_PORT,
        );

        return parse_url(
            $origin,
            PHP_URL_SCHEME,
        ) === $request->getScheme()
            && parse_url(
                $origin,
                PHP_URL_HOST,
            ) === $request->getHost()
            && (null === $port || $port === $request->getPort());
    }

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return array<array-key, mixed>
     */
    private function withoutCredentials(array $parameters): array
    {
        $kept = [];

        /** @var mixed $value */
        foreach ($parameters as $name => $value) {
            if ($this->isCredential((string) $name)) {
                continue;
            }

            $kept[$name] = is_array($value)
                ? $this->withoutCredentials($value)
                : $value;
        }

        return $kept;
    }

    private function isCredential(string $name): bool
    {
        if ($name === $this->csrfFieldName) {
            return false;
        }

        $name = strtolower($name);

        return str_contains(
            $name,
            'password',
        )
            || in_array(
                $name,
                self::CREDENTIAL_FIELDS,
                true,
            );
    }

    /**
     * Writes the uploads to storage and returns the shape of the upload fields with each file replaced by where it
     * was written. A file that cannot be written is left out rather than failing the stash: the rest of the form is
     * still worth keeping.
     *
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, mixed>
     */
    private function keepUploads(
        array $files,
        string $id,
        int &$written = 0,
        int &$bytes = 0,
    ): array {
        $kept = [];

        /** @var mixed $file */
        foreach ($files as $name => $file) {
            if (is_array($file)) {
                $kept[$name] = $this->keepUploads(
                    $file,
                    $id,
                    $written,
                    $bytes,
                );

                continue;
            }

            if (
                !$file instanceof UploadedFile
                || !$file->isValid()
                || $written >= self::MAX_FILES
            ) {
                continue;
            }

            $size = $file->getSize();
            if (
                !is_int($size)
                || $size <= 0
                || $size > StorageNamespace::SudoStash->maxFileSizeBytes()
                || $bytes + $size > self::MAX_TOTAL_BYTES
            ) {
                continue;
            }

            $path = $this->uploadPath(
                $id,
                $written,
            );
            if (null === $path) {
                continue;
            }

            $stream = fopen(
                $file->getPathname(),
                'rb',
            );
            if (!is_resource($stream)) {
                continue;
            }

            try {
                $this->fileStorage->writeStream(
                    $path,
                    $stream,
                );
            } catch (Throwable $failure) {
                $this->logger->warning(
                    'Could not keep an upload for a refused write.',
                    ['exception' => $failure],
                );

                continue;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $kept[$name] = [
                'index' => $written,
                'name' => $file->getClientOriginalName(),
                'type' => $file->getClientMimeType(),
            ];

            ++$written;
            $bytes += $size;
        }

        return $kept;
    }

    private function discardUploads(string $id): void
    {
        if (!$this->isWellFormed($id)) {
            return;
        }

        $this->fileStorage->deleteDirectory(StorageNamespace::SudoStash->directory($id));
    }

    /** The namespace is scoped per stash, so the root is the parent of the directory one stash writes into. */
    private function uploadRoot(): string
    {
        return dirname(StorageNamespace::SudoStash->directory('any'));
    }

    private function keyFor(string $id): ?string
    {
        if (!$this->isWellFormed($id)) {
            return null;
        }

        $suffix = $this->session->current();

        return null === $suffix
            ? null
            : $this->key(
                $suffix,
                $id,
            );
    }

    /** Stated once, because the layout of the key is what scopes a stash to one session and one firewall. */
    private function key(
        string $suffix,
        string $id,
    ): string {
        return self::KEY_PREFIX . $suffix . '_' . $id;
    }

    private function isWellFormed(string $id): bool
    {
        return 1 === preg_match(
            self::ID_PATTERN,
            $id,
        );
    }
}
