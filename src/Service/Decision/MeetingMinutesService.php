<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Decision\Enums\MeetingActivityVerbs;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingMinutes;
use App\Entity\Decision\MeetingMinutesVersion;
use App\Entity\User\User;
use App\Service\Application\FileStorage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The write side of meeting minutes: at most one set of minutes per meeting, with an appended version per upload.
 */
final readonly class MeetingMinutesService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private FileStorage $fileStorage,
        private MeetingActivityLogger $activityLogger,
    ) {
    }

    public function uploadMinutes(
        Meeting $meeting,
        UploadedFile $file,
        string $versionLabel,
        User $actor,
    ): MeetingMinutesVersion {
        $minutes = $meeting->getMinutes();

        if (null === $minutes) {
            $minutes = new MeetingMinutes();
            $minutes->setMeeting($meeting);
            $this->entityManager->persist($minutes);
        }

        $version = new MeetingMinutesVersion();
        $version->setMinutes($minutes);
        $version->versionLabel = $versionLabel;
        $version->path = $this->fileStorage->store(
            StorageNamespace::MeetingMinutes,
            $file->getPathname(),
            $meeting->getStorageScope(),
        )->path;
        $version->uploadedBy = $actor;
        $version->uploadedAt = new DateTimeImmutable();

        $this->entityManager->persist($version);
        $this->activityLogger->log(
            $actor,
            $meeting,
            MeetingActivityVerbs::MinutesUploaded,
            $versionLabel,
        );
        $this->entityManager->flush();

        return $version;
    }

    /**
     * Removes the minutes with all their versions, unlinking their stored files once the rows are gone.
     */
    public function deleteMinutes(
        Meeting $meeting,
        User $actor,
    ): void {
        $minutes = $meeting->getMinutes();

        if (null === $minutes) {
            return;
        }

        $paths = [];
        foreach ($minutes->getVersions() as $version) {
            $paths[] = $version->path;
            $this->entityManager->remove($version);
        }

        $this->entityManager->remove($minutes);
        $meeting->setMeetingMinutes(null);
        $this->activityLogger->log(
            $actor,
            $meeting,
            MeetingActivityVerbs::MinutesDeleted,
            '',
        );
        $this->entityManager->flush();

        foreach ($paths as $path) {
            $this->fileStorage->remove($path);
        }
    }
}
