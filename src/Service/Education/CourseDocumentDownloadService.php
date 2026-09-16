<?php

declare(strict_types=1);

namespace App\Service\Education;

use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Education\CourseDocument;
use App\Entity\Education\CourseDocumentDownload;
use App\Entity\Education\Enums\DownloadStatus;
use App\Entity\Education\Exam;
use App\Entity\Education\Summary;
use App\Entity\User\User;
use App\Message\Education\BuildWatermarkedDocumentMessage;
use App\Repository\Education\CourseDocumentDownloadRepository;
use App\Service\Application\FileStorage;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;

use function count;
use function implode;
use function sprintf;

/**
 * Building a watermarked copy means compositing and re-encoding every page, which is fast but not instant. Doing that
 * inline is what used to exhaust the PHP time limit on a long exam, so nothing here is served from the request thread.
 */
final readonly class CourseDocumentDownloadService
{
    /**
     * How long a request and anything built for it is kept. A build takes about a second and the waiting page collects
     * it as soon as it is ready, so a minute already covers the whole exchange; requesting again costs another second.
     */
    private const string RETENTION = 'PT1M';

    public function __construct(
        private FileStorage $fileStorage,
        private WatermarkedPdfBuilder $pdfBuilder,
        private CourseDocumentDownloadRepository $downloadRepository,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private SluggerInterface $slugger,
    ) {
    }

    public function request(
        CourseDocument $document,
        ?User $user,
        ?string $clientIp,
    ): CourseDocumentDownload {
        $download = new CourseDocumentDownload();
        $download->token = Uuid::v4();
        $download->document = $document;
        $download->requestedBy = $user;
        // An anonymous request from campus still has to be attributable, so the watermark names its address.
        $download->requestedByName = $user?->getDisplayName() ?? $clientIp ?? 'an anonymous visitor';
        $download->requestedFrom = $clientIp ?? '';
        $download->requestedAt = new DateTimeImmutable();

        $this->entityManager->persist($download);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new BuildWatermarkedDocumentMessage($download->id ?? 0));

        return $download;
    }

    public function build(CourseDocumentDownload $download): void
    {
        $path = sprintf(
            '%s/%s.pdf',
            StorageNamespace::EducationDownload->directory(),
            $download->token->toRfc4122(),
        );

        $this->fileStorage->write(
            $path,
            $this->pdfBuilder->build($download),
        );

        $download->path = $path;
        $download->status = DownloadStatus::Ready;
        $this->entityManager->flush();
    }

    public function markFailed(CourseDocumentDownload $download): void
    {
        $download->status = DownloadStatus::Failed;
        $this->entityManager->flush();
    }

    public function markCollected(CourseDocumentDownload $download): void
    {
        $download->collectedAt = new DateTimeImmutable();
        $this->entityManager->flush();
    }

    public function purgeExpired(): int
    {
        $expired = $this->downloadRepository->findExpired(
            new DateTimeImmutable()->sub(new DateInterval(self::RETENTION)),
        );

        foreach ($expired as $download) {
            $path = $download->path;

            $this->entityManager->remove($download);
            $this->entityManager->flush();

            if (null === $path) {
                continue;
            }

            $this->fileStorage->remove($path);
        }

        return count($expired);
    }

    /**
     * Mirrors the previous site, so a member's own archive keeps sorting the way it used to.
     */
    public function filenameFor(CourseDocument $document): string
    {
        $parts = [$document->course->code];

        if ($document instanceof Summary) {
            $author = $document->author;
            if (null !== $author) {
                $parts[] = $this->slugger->slug($author)->toString();
            }

            $parts[] = 'summary';
        } elseif ($document instanceof Exam) {
            $parts[] = $document->examType->value;
        }

        $parts[] = $document->date->format('Y-m-d');

        return implode(
            '-',
            $parts,
        ) . '.pdf';
    }
}
