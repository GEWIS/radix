<?php

declare(strict_types=1);

namespace App\Command\Frontpage;

use App\Entity\Frontpage\Page;
use App\Repository\Frontpage\PageRepository;
use App\Service\Frontpage\PageImageStore;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_unique;
use function array_values;
use function count;
use function sprintf;
use function str_replace;

/**
 * Files the images of pages written before a page had a directory of its own, read out of the page content, which is
 * the only thing that says which page shows which file. A file two pages share is copied to both and only unlinked
 * once neither names it any more; a file no page names is left alone.
 */
#[AsCommand(
    name: 'app:page:migrate-images',
    description: 'File the images of existing custom pages under the pages that show them.',
)]
final class MigratePageImagesCommand extends Command
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly PageImageStore $pageImageStore,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would move without writing anything.',
        );
    }

    #[Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle(
            $input,
            $output,
        );

        $dryRun = true === $input->getOption('dry-run');

        $filed = 0;
        $missing = 0;
        $pages = 0;
        /** @var list<string> $adopted */
        $adopted = [];

        foreach ($this->pageRepository->findAll() as $page) {
            $paths = $this->legacyPaths($page);

            if ([] === $paths) {
                continue;
            }

            ++$pages;
            $io->writeln(sprintf(
                'Page #%d shows %d image(s) that are not filed under it.',
                $page->getId() ?? 0,
                count($paths),
            ));

            foreach ($paths as $path) {
                if ($dryRun) {
                    ++$filed;

                    continue;
                }

                $destination = $this->pageImageStore->adopt(
                    $page,
                    $path,
                );

                if (null === $destination) {
                    ++$missing;
                    $io->warning(sprintf(
                        'Page #%d names "%s", which is not in storage.',
                        $page->getId() ?? 0,
                        $path,
                    ));

                    continue;
                }

                $this->rewrite(
                    $page,
                    $path,
                    $destination,
                );
                $adopted[] = $path;
                ++$filed;
            }
        }

        if ($dryRun) {
            $io->note(sprintf(
                '%d image(s) across %d page(s) would be filed. Nothing was written.',
                $filed,
                $pages,
            ));

            return Command::SUCCESS;
        }

        $this->entityManager->flush();

        // Every page has been rewritten by now, so nothing still points at the flat file.
        $discarded = 0;
        foreach (array_values(array_unique($adopted)) as $path) {
            if (!$this->pageImageStore->discardLegacy($path)) {
                continue;
            }

            ++$discarded;
        }

        $io->success(sprintf(
            'Filed %d image(s) under %d page(s) and unlinked %d that nothing points at any more.',
            $filed,
            $pages,
            $discarded,
        ));

        if (0 !== $missing) {
            $io->warning(sprintf(
                '%d image(s) a page names are not in storage; those pages were left as they are.',
                $missing,
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function legacyPaths(Page $page): array
    {
        $content = $page->getContent();

        return array_values(array_unique([
            ...$this->pageImageStore->legacyPaths($content->getValueEN()),
            ...$this->pageImageStore->legacyPaths($content->getValueNL()),
        ]));
    }

    private function rewrite(
        Page $page,
        string $from,
        string $to,
    ): void {
        $content = $page->getContent();

        $content->updateValues(
            $this->replace(
                $content->getValueEN(),
                $from,
                $to,
            ),
            $this->replace(
                $content->getValueNL(),
                $from,
                $to,
            ),
        );
    }

    /** An empty string is not the same thing as no text at all. */
    private function replace(
        ?string $content,
        string $from,
        string $to,
    ): ?string {
        if (null === $content) {
            return null;
        }

        return str_replace(
            $from,
            $to,
            $content,
        );
    }
}
