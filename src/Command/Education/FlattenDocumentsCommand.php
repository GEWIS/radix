<?php

declare(strict_types=1);

namespace App\Command\Education;

use App\Command\HoldsRunLockTrait;
use App\Entity\Education\Enums\DocumentFlattenStatus;
use App\Repository\Education\CourseDocumentRepository;
use App\Service\Education\CourseDocumentFlattener;
use App\Service\Education\PdfRasterizerException;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function count;
use function intval;
use function max;
use function sprintf;
use function usleep;

/**
 * Walks in-process on purpose: queueing thousands of documents onto the `images` transport would starve the variant
 * generation the serving path waits on. Fresh uploads still go through the queue one at a time.
 */
#[AsCommand(
    name: 'app:education:flatten-documents',
    description: 'Rasterize course documents that have not been flattened yet, at a gentle pace.',
)]
final class FlattenDocumentsCommand extends Command
{
    use HoldsRunLockTrait;

    public function __construct(
        private readonly CourseDocumentRepository $documentRepository,
        private readonly CourseDocumentFlattener $flattener,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'delay',
                null,
                InputOption::VALUE_REQUIRED,
                'Milliseconds to pause after each document, the knob that keeps the host responsive.',
                '1000',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Stop after this many documents (0 means no limit), for bounded off-peak batches.',
                '0',
            )
            ->addOption(
                'retry-failed',
                null,
                InputOption::VALUE_NONE,
                'Also process documents that failed, rather than only ones never tried.',
            );
    }

    #[Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        return $this->runExclusively(
            $output,
            fn (): int => $this->flattenPending(
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
                (bool) $input->getOption('retry-failed'),
            ),
        );
    }

    private function flattenPending(
        SymfonyStyle $io,
        int $delayMs,
        int $limit,
        bool $retryFailed,
    ): int {
        $statuses = $retryFailed
            ? [
                DocumentFlattenStatus::Pending,
                DocumentFlattenStatus::Failed,
            ]
            : [DocumentFlattenStatus::Pending];

        // Ids up front, re-found per turn: the identity map is cleared between documents, detaching anything held.
        $documentIds = [];
        foreach (
            $this->documentRepository->findByFlattenStatus(
                $statuses,
                0 !== $limit ? $limit : null,
            ) as $document
        ) {
            $documentIds[] = (int) $document->getId();
        }

        $this->entityManager->clear();

        $flattened = 0;
        $failed = 0;

        foreach ($documentIds as $documentId) {
            $document = $this->documentRepository->find($documentId);
            if (null === $document) {
                // Deleted since the list was taken.
                continue;
            }

            try {
                $this->flattener->flatten($document);
                $flattened++;

                if ($io->isVerbose()) {
                    $io->writeln(sprintf(
                        'Flattened document %d',
                        $documentId,
                    ));
                }
            } catch (PdfRasterizerException $e) {
                // Unreadable by poppler is a bad upload; record it so an administrator can replace the file.
                $this->flattener->markFailed(
                    $document,
                    $e->getMessage(),
                );
                $failed++;

                $io->warning(sprintf(
                    'Could not flatten document %d: %s',
                    $documentId,
                    $e->getMessage(),
                ));
            } catch (Throwable $e) {
                // Not the document's fault (missing file, say), so its status is left alone rather than marked bad.
                $failed++;

                $io->warning(sprintf(
                    'Could not process document %d: %s',
                    $documentId,
                    $e->getMessage(),
                ));
            }

            $this->entityManager->clear();

            if ($delayMs <= 0) {
                continue;
            }

            usleep($delayMs * 1000);
        }

        $io->listing([
            sprintf(
                'Documents processed: %d',
                count($documentIds),
            ),
            sprintf(
                'Flattened: %d',
                $flattened,
            ),
            sprintf(
                'Failed (marked on the document): %d',
                $failed,
            ),
        ]);

        if (
            0 !== $limit
            && count($documentIds) === $limit
        ) {
            $io->note('Stopped at --limit; run again to continue with the next batch.');
        } elseif (0 === count($documentIds)) {
            $io->success('Nothing left to flatten.');
        }

        return $failed > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
