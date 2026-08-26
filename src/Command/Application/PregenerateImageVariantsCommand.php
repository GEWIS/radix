<?php

declare(strict_types=1);

namespace App\Command\Application;

use App\Command\HoldsRunLockTrait;
use App\Entity\Application\Enums\ImageProfile;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use App\Message\Application\PregenerateImageVariantMessage;
use App\Repository\Career\CompanyBannerPackageRepository;
use App\Service\Application\FilePathResolver;
use App\Service\Application\FileStorage;
use App\Service\Application\ImageVariantResponder;
use App\Service\Application\VariantGenerator;
use Override;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function array_key_exists;
use function intval;
use function is_string;
use function max;
use function sprintf;
use function str_starts_with;
use function usleep;

/**
 * Queues the missing image variants of every stored image onto the `images` transport.
 *
 * The command walks storage and dispatches; it never encodes. Encoding happens in the `messenger-images` workers,
 * which is where the image pipeline's CPU and memory limits are set and what `IMAGE_WORKER_REPLICAS` scales. Doing
 * it inline (as this command once did) put the whole backfill in whichever container the command was run from,
 * single-threaded, competing with whatever else that container does.
 */
#[AsCommand(
    name: 'app:image:pregenerate',
    description: 'Queue the image variants of all stored images onto the images transport.',
)]
final class PregenerateImageVariantsCommand extends Command
{
    use HoldsRunLockTrait;

    /** Career is absent on purpose: logos and banners share `career/{id}/images`, so only the database separates them. */
    private const array UNAMBIGUOUS_PREFIXES = [
        'photos/albums',
        'photos/covers',
        'photos/weekly',
        'organs/images',
        'pages/images',
    ];

    private const string CAREER_PREFIX = 'career';

    /** Deferred markers accumulate in the pool until committed, so a full backfill must flush as it goes. */
    private const int PENDING_COMMIT_BATCH = 500;

    public function __construct(
        private readonly FileStorage $fileStorage,
        private readonly FilePathResolver $pathResolver,
        private readonly VariantGenerator $variantGenerator,
        private readonly CompanyBannerPackageRepository $bannerPackageRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Re-encode every variant instead of only the missing ones, for a changed variant set, quality or encoder.',
        );
        $this->addOption(
            'delay',
            null,
            InputOption::VALUE_REQUIRED,
            'Milliseconds to pause after each dispatch, to keep a large backfill from arriving at the broker at once.',
            '0',
        );
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Stop after queueing this many variants (0 means no limit), for bounded off-peak batches.',
            '0',
        );
        $this->addOption(
            'prefix',
            null,
            InputOption::VALUE_REQUIRED,
            'Only consider sources under this stored-path prefix (e.g. photos/albums/123).',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Only count what would be queued, without dispatching anything.',
        );
    }

    #[Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $prefix = $input->getOption('prefix');

        return $this->runExclusively(
            $output,
            fn (): int => $this->pregenerate(
                new SymfonyStyle(
                    $input,
                    $output,
                ),
                max(
                    0,
                    intval($input->getOption('delay')),
                ),
                max(
                    0,
                    intval($input->getOption('limit')),
                ),
                is_string($prefix) ? $prefix : null,
                (bool) $input->getOption('dry-run'),
                (bool) $input->getOption('force'),
            ),
        );
    }

    private function pregenerate(
        SymfonyStyle $io,
        int $delayMs,
        int $limit,
        ?string $prefix,
        bool $dryRun,
        bool $force,
    ): int {
        $sources = 0;
        $existing = 0;
        $wanted = 0;
        $queued = 0;
        $failed = 0;
        $limitReached = false;
        $uncommitted = 0;

        $bannerProfiles = $this->bannerProfilesByPath();

        foreach ($this->sourcePaths() as $path) {
            if (
                null !== $prefix
                && !str_starts_with(
                    $path,
                    $prefix,
                )
            ) {
                continue;
            }

            $profile = $this->profileFor(
                $path,
                $bannerProfiles,
            );
            if (null === $profile) {
                continue;
            }

            $sources++;

            foreach ($profile->variants() as $variant) {
                // Under --force every variant is queued regardless, so the existence check is not just irrelevant
                // but a stat per variant that buys nothing.
                if (!$force) {
                    if (
                        $this->variantGenerator->variantExists(
                            $path,
                            $variant,
                        )
                    ) {
                        $existing++;
                        continue;
                    }
                }

                $wanted++;

                if ($dryRun) {
                    continue;
                }

                if (
                    0 !== $limit
                    && $queued >= $limit
                ) {
                    $limitReached = true;
                    break 2;
                }

                try {
                    $this->messageBus->dispatch(new PregenerateImageVariantMessage(
                        $path,
                        $variant,
                        $force,
                    ));
                } catch (Throwable $throwable) {
                    // A broken transport fails every dispatch, so warn once per source rather than per variant.
                    $io->warning(sprintf(
                        'Failed to queue %s (%s): %s',
                        $path,
                        $variant->value,
                        $throwable->getMessage(),
                    ));
                    $failed++;
                    continue 2;
                }

                $queued++;

                if (!$force) {
                    // A forced variant is still in the cache and so is still served; only a missing one can draw a
                    // duplicate message out of the serving path while this one waits on the transport.
                    $this->markPending(
                        $path,
                        $variant,
                    );
                    $uncommitted++;

                    if ($uncommitted >= self::PENDING_COMMIT_BATCH) {
                        $this->cache->commit();
                        $uncommitted = 0;
                    }
                }

                if ($io->isVerbose()) {
                    $io->writeln(sprintf(
                        'Queued %s of %s',
                        $variant->value,
                        $path,
                    ));
                }

                if ($delayMs <= 0) {
                    continue;
                }

                usleep($delayMs * 1000);
            }
        }

        $this->cache->commit();

        $listing = [
            sprintf(
                'Sources considered: %d',
                $sources,
            ),
        ];

        if (!$force) {
            $listing[] = sprintf(
                'Variants already present: %d',
                $existing,
            );
        }

        $listing[] = sprintf(
            $force ? 'Variants to re-encode: %d' : 'Variants missing: %d',
            $wanted,
        );
        $listing[] = sprintf(
            'Variants queued: %s',
            $dryRun ? 'none (dry run)' : (string) $queued,
        );
        $listing[] = sprintf(
            'Sources that failed to queue: %d',
            $failed,
        );

        $io->listing($listing);

        if ($limitReached) {
            $io->note('Stopped at --limit; run again to continue where the missing variants start.');
        } elseif (
            !$dryRun
            && 0 === $wanted
        ) {
            $io->success('Nothing left to queue.');
        }

        if (
            !$dryRun
            && $queued > 0
        ) {
            $io->note('Queued, not encoded. The messenger-images workers do the work; scale IMAGE_WORKER_REPLICAS.');
        }

        return $failed > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    /**
     * Claim the serving path's pending marker for a variant now on the transport, so a visitor who hits it before a
     * worker gets there answers 503 without dispatching a second message ({@see ImageVariantResponder}).
     */
    private function markPending(
        string $path,
        ImageVariant $variant,
    ): void {
        $marker = $this->cache->getItem(ImageVariantResponder::pendingCacheKey(
            $path,
            $variant,
        ));

        $marker->set(true);
        $marker->expiresAfter(ImageVariantResponder::PENDING_TTL);
        $this->cache->saveDeferred($marker);
    }

    /** @return iterable<string> */
    private function sourcePaths(): iterable
    {
        foreach (self::UNAMBIGUOUS_PREFIXES as $directory) {
            yield from $this->fileStorage->listFiles(
                $directory,
                true,
            );
        }

        // Also yields attachments (PDFs); profileFor() drops everything that is not an image.
        yield from $this->fileStorage->listFiles(
            self::CAREER_PREFIX,
            true,
        );
    }

    /** @param array<string, ImageProfile> $bannerProfiles */
    private function profileFor(
        string $path,
        array $bannerProfiles,
    ): ?ImageProfile {
        $namespace = $this->pathResolver->namespaceForPath($path);
        if (null === $namespace) {
            return null;
        }

        if (StorageNamespace::CompanyImage === $namespace) {
            // Banners and logos share the namespace; whatever no package points at is a logo.
            return $bannerProfiles[$path] ?? ImageProfile::CompanyLogo;
        }

        return $this->pathResolver->profileForPath(
            $path,
            ImageVariant::W320,
        );
    }

    /** @return array<string, ImageProfile> */
    private function bannerProfilesByPath(): array
    {
        $profiles = [];
        foreach ($this->bannerPackageRepository->findAll() as $package) {
            $profile = $package->getFormat()->imageProfile();

            foreach (
                [
                    $package->getImage(),
                    $package->getPendingImage(),
                ] as $path
            ) {
                if (null === $path) {
                    continue;
                }

                // Sharing a content-addressed file implies the same company and box, so first profile wins.
                if (
                    array_key_exists(
                        $path,
                        $profiles,
                    )
                ) {
                    continue;
                }

                $profiles[$path] = $profile;
            }
        }

        return $profiles;
    }
}
