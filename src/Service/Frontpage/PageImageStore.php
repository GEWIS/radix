<?php

declare(strict_types=1);

namespace App\Service\Frontpage;

use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Frontpage\FrontpageLocalisedText;
use App\Entity\Frontpage\Page;
use App\Message\Frontpage\ProcessPageImageMessage;
use App\Service\Application\FileStorage;
use App\Service\Application\StoredFile;
use App\Service\Application\VariantGenerator;
use App\ViewModel\Frontpage\PageImage;
use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_pop;
use function array_unique;
use function array_values;
use function basename;
use function explode;
use function hash;
use function implode;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strval;
use function substr;
use function substr_count;
use function usort;

/**
 * The directory a page's images live in is the whole record of them; there is no table, which is what lets the
 * browser still offer an image the HTML no longer shows. The files the old website left lie flat in `pages/images`
 * and are not filed under any page.
 */
final readonly class PageImageStore
{
    /** Where a page that has no id yet parks its uploads; a page id cannot collide, since ids are numbers. */
    private const string PENDING = 'pending';

    /** After this an image is taken to be finished, so a worker that died cannot block it for good. */
    private const int PENDING_TTL = 900;

    private const string RUN_PATTERN = '/\A[0-9a-f]{16}\z/';

    private const string PATH_IN_CONTENT = '#/img/[a-z0-9]+/(%s/[^"\'\s?\#]+)#';

    public function __construct(
        private FileStorage $fileStorage,
        private VariantGenerator $variantGenerator,
        private MessageBusInterface $messageBus,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function topic(string $scope): string
    {
        return sprintf(
            'frontpage/page-images/%s',
            $scope,
        );
    }

    public function isPending(string $path): bool
    {
        return $this->cache->getItem($this->pendingKey($path))->isHit();
    }

    public function settle(string $path): void
    {
        $this->cache->deleteItem($this->pendingKey($path));
    }

    /** Null when there is nothing to file an image under; the scope ends up in a path, so a caller has to refuse. */
    public function scope(
        ?Page $page,
        ?string $flowRun,
    ): ?string {
        // An unsaved page has no id, and an empty scope is the flat legacy namespace rather than a page's own.
        if (null !== $page) {
            $id = $page->getId();

            return null === $id
                ? null
                : strval($id);
        }

        if (
            null === $flowRun
            || 1 !== preg_match(
                self::RUN_PATTERN,
                $flowRun,
            )
        ) {
            return null;
        }

        return sprintf(
            '%s/%s',
            self::PENDING,
            $flowRun,
        );
    }

    public function store(
        string $localPath,
        string $scope,
    ): StoredFile {
        $stored = $this->fileStorage->store(
            StorageNamespace::PageImage,
            $localPath,
            $scope,
        );

        $this->process(
            $stored->path,
            $scope,
        );

        return $stored;
    }

    /**
     * @return list<PageImage>
     */
    public function list(string $scope): array
    {
        $images = [];

        foreach ($this->fileStorage->listFiles($this->directory($scope)) as $path) {
            $images[] = new PageImage(
                $path,
                new DateTimeImmutable()->setTimestamp($this->fileStorage->lastModified($path)),
                !$this->isPending($path),
            );
        }

        usort(
            $images,
            static fn (PageImage $a, PageImage $b): int => $b->uploadedAt <=> $a->uploadedAt,
        );

        return $images;
    }

    /** Answers whether anything moved, so the caller knows to flush. */
    public function claim(
        Page $page,
        string $flowRun,
        FrontpageLocalisedText $content,
    ): bool {
        $pending = $this->scope(
            null,
            $flowRun,
        );
        $claimed = $this->scope(
            $page,
            null,
        );

        if (
            null === $pending
            || null === $claimed
        ) {
            return false;
        }

        $from = $this->directory($pending);
        $paths = $this->fileStorage->listFiles($from);

        if ([] === $paths) {
            return false;
        }

        $to = $this->directory($claimed);

        foreach ($paths as $path) {
            $destination = sprintf(
                '%s/%s',
                $to,
                basename($path),
            );

            $this->fileStorage->move(
                $path,
                $destination,
            );
            // Variants are keyed by the source path, so the ones under the old path are of no use to anybody.
            $this->variantGenerator->purge($path);
            $this->settle($path);
            $this->process(
                $destination,
                $claimed,
            );
        }

        $this->fileStorage->deleteDirectory($from);

        $content->updateValues(
            $this->rewrite(
                $content->getValueEN(),
                $from,
                $to,
            ),
            $this->rewrite(
                $content->getValueNL(),
                $from,
                $to,
            ),
        );

        return true;
    }

    /** The path arrives as an unsigned argument, so it is checked against the directory the scope owns. */
    public function remove(
        string $scope,
        string $path,
    ): bool {
        $prefix = $this->directory($scope) . '/';

        if (
            !str_starts_with(
                $path,
                $prefix,
            )
        ) {
            return false;
        }

        $name = substr(
            $path,
            strlen($prefix),
        );
        if (
            '' === $name
            || str_contains(
                $name,
                '/',
            )
        ) {
            return false;
        }

        if (!$this->fileStorage->remove($path)) {
            return false;
        }

        $this->variantGenerator->purge($path);
        $this->settle($path);

        return true;
    }

    public function removeAll(string $scope): void
    {
        $directory = $this->directory($scope);

        foreach ($this->fileStorage->listFiles($directory) as $path) {
            if (!$this->fileStorage->remove($path)) {
                continue;
            }

            $this->variantGenerator->purge($path);
            $this->settle($path);
        }

        $this->fileStorage->deleteDirectory($directory);
    }

    /** Answers how many files went. */
    public function prune(DateTimeImmutable $before): int
    {
        $root = $this->directory(self::PENDING);
        $pruned = 0;
        $runs = [];

        foreach (
            $this->fileStorage->listFiles(
                $root,
                true,
            ) as $path
        ) {
            if ($this->fileStorage->lastModified($path) >= $before->getTimestamp()) {
                continue;
            }

            $this->fileStorage->delete($path);
            $this->variantGenerator->purge($path);
            $this->settle($path);
            $runs[] = $this->parentDirectory($path);
            ++$pruned;
        }

        // A run key is minted per arrival at the form, so an emptied run directory is written to again by nobody.
        foreach (array_unique($runs) as $run) {
            if ([] !== $this->fileStorage->listFiles($run)) {
                continue;
            }

            $this->fileStorage->deleteDirectory($run);
        }

        return $pruned;
    }

    /**
     * @return list<string>
     */
    public function legacyPaths(?string $content): array
    {
        if (null === $content) {
            return [];
        }

        $pattern = sprintf(
            self::PATH_IN_CONTENT,
            preg_quote(
                $this->root(),
                '#',
            ),
        );

        if (
            1 > preg_match_all(
                $pattern,
                $content,
                $matches,
            )
        ) {
            return [];
        }

        $paths = [];
        foreach ($matches[1] as $path) {
            if (!$this->isLegacy($path)) {
                continue;
            }

            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /** Copied rather than moved: another page may still be pointing at the same file. Null when it is gone. */
    public function adopt(
        Page $page,
        string $legacyPath,
    ): ?string {
        $scope = $this->scope(
            $page,
            null,
        );

        if (
            null === $scope
            || !$this->fileStorage->exists($legacyPath)
        ) {
            return null;
        }

        $destination = sprintf(
            '%s/%s',
            $this->directory($scope),
            basename($legacyPath),
        );

        if (!$this->fileStorage->exists($destination)) {
            $this->fileStorage->copy(
                $legacyPath,
                $destination,
            );
        }

        $this->process(
            $destination,
            $scope,
        );

        return $destination;
    }

    /** Refuses anything filed under a page, so this cannot reach a page's own directory. */
    public function discardLegacy(string $path): bool
    {
        if (!$this->isLegacy($path)) {
            return false;
        }

        if (!$this->fileStorage->remove($path)) {
            return false;
        }

        $this->variantGenerator->purge($path);

        return true;
    }

    private function isLegacy(string $path): bool
    {
        $root = $this->root();

        return str_starts_with(
            $path,
            $root . '/',
        )
            && substr_count(
                $path,
                '/',
            ) === substr_count(
                $root . '/',
                '/',
            )
            && !str_ends_with(
                $path,
                '/',
            );
    }

    private function root(): string
    {
        return StorageNamespace::PageImage->directory();
    }

    /** The marker is raised first, or a transport that handles the message inline would settle it before it exists. */
    private function process(
        string $path,
        string $scope,
    ): void {
        $pending = $this->cache->getItem($this->pendingKey($path));
        $pending->set(true);
        $pending->expiresAfter(self::PENDING_TTL);
        $this->cache->save($pending);

        $this->messageBus->dispatch(new ProcessPageImageMessage(
            $path,
            $scope,
        ));
    }

    /** Hashed because a stored path contains characters PSR-6 reserves. */
    private function pendingKey(string $path): string
    {
        return 'page_image_pending.' . hash(
            'sha256',
            $path,
        );
    }

    private function directory(string $scope): string
    {
        return StorageNamespace::PageImage->directory($scope);
    }

    private function rewrite(
        ?string $content,
        string $from,
        string $to,
    ): ?string {
        if (null === $content) {
            return null;
        }

        return str_replace(
            $from . '/',
            $to . '/',
            $content,
        );
    }

    private function parentDirectory(string $path): string
    {
        $segments = explode(
            '/',
            $path,
        );
        array_pop($segments);

        return implode(
            '/',
            $segments,
        );
    }
}
