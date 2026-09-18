<?php

declare(strict_types=1);

namespace App\Security\User;

use App\Service\Application\FileStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function fclose;
use function fopen;
use function is_array;
use function is_file;
use function is_int;
use function is_resource;
use function is_string;
use function parse_url;
use function stream_copy_to_stream;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_URL_PATH;

/**
 * Re-runs a write that was refused for want of a sudo grant, once the grant has been given.
 *
 * Only for an action that declares {@see \App\Attribute\User\Replayable}. Everything else returns null, and the
 * caller returns the user to the page instead.
 *
 * The write is run as a request of its own, which is what allows the uploads to be part of it: a form returned to
 * the browser cannot include a file. It is handled as a main request rather than a sub-request, because the
 * listeners that refuse a write stand down for a sub-request: the read-only maintenance window and the firewall's
 * `access_control` both test `isMainRequest()`, and a re-run that skipped them would do what the user could not have
 * done by hand.
 *
 * The statement that the request came from this site is copied from the confirmation the user has just submitted,
 * which was checked like any other submission, and the stash was only kept for a request that made the same
 * statement itself ({@see SudoStash}). The CSRF token is the one that was submitted the first time, and is
 * validated on the way through as it would have been then.
 */
final readonly class SudoReplay
{
    /**
     * What states that a request was made from a page of this site. Copied onto the re-run because the CSRF token
     * manager reads them from the request being handled, which is the re-run and not the confirmation it was sent
     * from.
     */
    private const array ORIGIN_HEADERS = [
        'Origin',
        'Referer',
        'Sec-Fetch-Site',
        'Sec-Fetch-Mode',
        'Sec-Fetch-Dest',
    ];

    public function __construct(
        private SudoStash $stash,
        private SudoArea $area,
        private ReplayableAction $replayable,
        private RequestStack $requestStack,
        private FileStorage $fileStorage,
        private HttpKernelInterface $kernel,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The response of the re-run write, or null where there is nothing to re-run.
     */
    public function replay(string $id): ?Response
    {
        $write = $this->stash->read($id);
        if (null === $write) {
            return null;
        }

        $path = parse_url(
            $write->uri,
            PHP_URL_PATH,
        );
        if (!is_string($path)) {
            return null;
        }

        // Tested before the uploads are copied out of storage, which a record that does not match what wrote it
        // would otherwise be charged for.
        if (!$this->canReplay($path)) {
            return null;
        }

        $current = $this->requestStack->getMainRequest();
        if (null === $current) {
            return null;
        }

        $temporary = [];

        try {
            $rerun = Request::create(
                $write->uri,
                $write->method,
                $write->parameters,
                $current->cookies->all(),
                $this->restoreUploads(
                    $write->files,
                    $id,
                    $temporary,
                ),
                $current->server->all(),
            );

            $rerun->setSession($current->getSession());

            foreach (self::ORIGIN_HEADERS as $header) {
                $value = $current->headers->get($header);

                if (null === $value) {
                    continue;
                }

                $rerun->headers->set(
                    $header,
                    $value,
                );
            }

            // Referer states the page the write was made from rather than the prompt, which is what it was.
            $rerun->headers->set(
                'Referer',
                $current->getSchemeAndHttpHost() . $write->uri,
            );

            return $this->kernel->handle(
                $rerun,
                HttpKernelInterface::MAIN_REQUEST,
                false,
            );
        } catch (Throwable $failure) {
            $this->logger->error(
                'Could not re-run a write that was refused for want of a sudo grant.',
                ['exception' => $failure],
            );

            return null;
        } finally {
            $this->stash->discard($id);

            foreach ($temporary as $local) {
                if (!is_file($local)) {
                    continue;
                }

                unlink($local);
            }
        }
    }

    /**
     * What confirming will do with the stash, for the prompt to state before the user presses anything.
     */
    public function outcomeFor(string $id): RefusedWrite
    {
        $write = $this->stash->read($id);
        if (null === $write) {
            return RefusedWrite::None;
        }

        $path = parse_url(
            $write->uri,
            PHP_URL_PATH,
        );

        return is_string($path) && $this->canReplay($path)
            ? RefusedWrite::SentOnConfirmation
            : RefusedWrite::SubmittedByHand;
    }

    /**
     * Whether a kept write is sent again: it was refused behind sudo, and the action declares
     * {@see \App\Attribute\User\Replayable}.
     *
     * Both halves stated once, because the prompt says which of the two things confirming will do and confirming
     * has to do the one it said. Only a request behind sudo is kept, so a record naming anything else does not
     * match what wrote it.
     */
    private function canReplay(string $path): bool
    {
        return $this->area->coversPath($path)
            && $this->replayable->coversPath($path);
    }

    /**
     * Rebuilds the upload fields from what the stash wrote to storage. The bytes go back onto the local filesystem
     * because an UploadedFile is a path, and the action moves it from there as it would have moved the original.
     *
     * @param array<array-key, mixed> $files
     * @param list<string>            $temporary
     *
     * @return array<array-key, mixed>
     */
    private function restoreUploads(
        array $files,
        string $id,
        array &$temporary,
    ): array {
        $restored = [];

        /** @var mixed $file */
        foreach ($files as $name => $file) {
            if (!is_array($file)) {
                continue;
            }

            $index = $file['index'] ?? null;
            if (!is_int($index)) {
                $restored[$name] = $this->restoreUploads(
                    $file,
                    $id,
                    $temporary,
                );

                continue;
            }

            // The path is derived from the id and the position rather than read from the record, so a record that
            // was tampered with cannot name a file of another namespace to be copied out and handed to the action.
            $path = $this->stash->uploadPath(
                $id,
                $index,
            );
            if (null === $path) {
                continue;
            }

            $local = $this->copyOut($path);
            if (null === $local) {
                continue;
            }

            $temporary[] = $local;

            $restored[$name] = new UploadedFile(
                $local,
                is_string($file['name'] ?? null) ? $file['name'] : 'upload',
                is_string($file['type'] ?? null) ? $file['type'] : null,
                null,
                true,
            );
        }

        return $restored;
    }

    private function copyOut(string $path): ?string
    {
        if (!$this->fileStorage->exists($path)) {
            return null;
        }

        $local = tempnam(
            sys_get_temp_dir(),
            'gws_sudo_',
        );
        if (false === $local) {
            return null;
        }

        $source = $this->fileStorage->readStream($path);
        $target = fopen(
            $local,
            'wb',
        );

        if (
            !is_resource($source)
            || !is_resource($target)
        ) {
            unlink($local);

            return null;
        }

        stream_copy_to_stream(
            $source,
            $target,
        );

        fclose($source);
        fclose($target);

        return $local;
    }
}
