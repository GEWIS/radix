<?php

declare(strict_types=1);

namespace App\Command\Application;

use App\Command\HoldsRunLockTrait;
use App\Entity\Application\Enums\ImageProfile;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use App\Repository\Career\CompanyBannerPackageRepository;
use App\Service\Application\FilePathResolver;
use App\Service\Application\FileStorage;
use App\Service\Application\VariantGenerator;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function array_key_exists;
use function intval;
use function is_string;
use function max;
use function sprintf;
use function str_starts_with;
use function usleep;

#[AsCommand(
    name: 'app:image:pregenerate',
    description: 'Generate missing image variants for all stored images, at a gentle pace.',
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

    public function __construct(
        private readonly FileStorage $fileStorage,
        private readonly FilePathResolver $pathResolver,
        private readonly VariantGenerator $variantGenerator,
        private readonly CompanyBannerPackageRepository $bannerPackageRepository,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(
            'delay',
            null,
            InputOption::VALUE_REQUIRED,
            'Milliseconds to pause after each encoded variant, the knob that keeps the host responsive.',
            '500',
        );
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Stop after encoding this many variants (0 means no limit), for bounded off-peak batches.',
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
            'Only count the missing variants, without decoding or encoding anything.',
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
            ),
        );
    }

    private function pregenerate(
        SymfonyStyle $io,
        int $delayMs,
        int $limit,
        ?string $prefix,
        bool $dryRun,
    ): int {
        $sources = 0;
        $existing = 0;
        $missing = 0;
        $encoded = 0;
        $skippedNarrow = 0;
        $failedSources = 0;
        $limitReached = false;

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
                if (
                    $this->variantGenerator->variantExists(
                        $path,
                        $variant,
                    )
                ) {
                    $existing++;
                    continue;
                }

                $missing++;

                if ($dryRun) {
                    continue;
                }

                if (
                    0 !== $limit
                    && $encoded >= $limit
                ) {
                    $limitReached = true;
                    break 2;
                }

                try {
                    $wrote = $this->variantGenerator->generateVariant(
                        $path,
                        $variant,
                        $profile->webpQuality(),
                    );
                } catch (Throwable $throwable) {
                    $io->warning(sprintf(
                        'Failed on %s (%s): %s',
                        $path,
                        $variant->value,
                        $throwable->getMessage(),
                    ));
                    $failedSources++;
                    continue 2;
                }

                if ($wrote) {
                    $encoded++;
                    if ($io->isVerbose()) {
                        $io->writeln(sprintf(
                            'Generated %s of %s',
                            $variant->value,
                            $path,
                        ));
                    }
                } else {
                    // False means the target is wider than the original; the decode still cost CPU.
                    $skippedNarrow++;
                }

                if ($delayMs <= 0) {
                    continue;
                }

                usleep($delayMs * 1000);
            }
        }

        $io->listing([
            sprintf(
                'Sources considered: %d',
                $sources,
            ),
            sprintf(
                'Variants already present: %d',
                $existing,
            ),
            sprintf(
                'Variants missing: %d',
                $missing,
            ),
            sprintf(
                'Variants encoded: %s',
                $dryRun ? 'none (dry run)' : (string) $encoded,
            ),
            sprintf(
                'Skipped (original narrower than target): %d',
                $skippedNarrow,
            ),
            sprintf(
                'Sources that failed: %d',
                $failedSources,
            ),
        ]);

        if ($limitReached) {
            $io->note('Stopped at --limit; run again to continue where the missing variants start.');
        } elseif (
            !$dryRun
            && 0 === $missing
        ) {
            $io->success('Nothing left to generate.');
        }

        return $failedSources > 0
            ? Command::FAILURE
            : Command::SUCCESS;
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
