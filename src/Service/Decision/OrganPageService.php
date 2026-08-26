<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganInformation;
use App\Entity\Decision\OrganInformationRevision;
use App\Entity\User\User;
use App\Form\Decision\OrganPage\ImagesStepType;
use App\Service\Application\EditLockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function is_array;

/**
 * Writing the page a body keeps about itself.
 *
 * Saving an edit and letting go of the edit lock are one operation rather than two: a save that commits but leaves the
 * lock standing blocks the author out of their own draft until the lock's TTL lapses.
 *
 * A body that has no page yet gets one and its first draft together, because a page with no revision is a row nothing
 * can render and nothing can edit.
 */
final readonly class OrganPageService
{
    public function __construct(
        private EditLockService $editLockService,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private OrganImageUploadService $imageUploadService,
    ) {
    }

    /**
     * Put whichever images were handed in onto the draft, and crop the ones that were framed. Reports whether every
     * one of them was kept; a refusal leaves the previous image in place rather than costing the author their text.
     */
    public function applyImages(
        OrganInformationRevision $revision,
        ?UploadedFile $banner,
        ?UploadedFile $logo,
        mixed $bannerCrop,
        mixed $logoCrop,
    ): bool {
        $uploaded = true;

        if (null !== $banner) {
            $stored = $this->imageUploadService->uploadSource($banner);

            if (null === $stored) {
                $uploaded = false;
            } else {
                $revision->setBannerSource($stored);
                $revision->setBannerCrop(null);
                $revision->setBannerPath($stored);
            }
        }

        if (null !== $logo) {
            $stored = $this->imageUploadService->uploadSource($logo);

            if (null === $stored) {
                $uploaded = false;
            } else {
                $revision->setLogoSource($stored);
                $revision->setLogoCrop(null);
                $revision->setLogoPath($stored);
            }
        }

        $bannerCropped = $this->applyCrop(
            $bannerCrop,
            $revision->getBannerSource(),
            ImagesStepType::BANNER_MINIMUM_WIDTH,
            $revision->setBannerCrop(...),
            $revision->setBannerPath(...),
        );
        $logoCropped = $this->applyCrop(
            $logoCrop,
            $revision->getLogoSource(),
            ImagesStepType::LOGO_MINIMUM_WIDTH,
            $revision->setLogoCrop(...),
            $revision->setLogoPath(...),
        );

        return $uploaded
            && $bannerCropped
            && $logoCropped;
    }

    public function saveDraft(
        OrganInformation $page,
        OrganInformationRevision $draft,
        User $user,
    ): void {
        $draft->setAuthor($user->getMember());
        $draft->setLastEditedBy($user);

        $this->entityManager->flush();

        $this->editLockService->release(
            $page,
            $user,
        );
    }

    /**
     * A page for a body that has none yet, together with the draft that makes it editable.
     */
    public function createPage(
        Organ $organ,
        User $user,
    ): OrganInformation {
        $page = new OrganInformation();
        $page->setOrgan($organ);
        $organ->setOrganInformation($page);

        $this->entityManager->persist($page);

        $this->startFirstDraft(
            $page,
            $user,
        );

        return $page;
    }

    public function startFirstDraft(
        OrganInformation $page,
        User $user,
    ): void {
        $draft = new OrganInformationRevision();
        $draft->setAuthor($user->getMember());
        $page->addRevision($draft);
        $page->setCurrentRevision($draft);

        $this->entityManager->persist($draft);
        $this->entityManager->flush();
    }

    /**
     * Crop an image to the frame that was drawn on it, if one was. Reports whether it worked; a frame that cannot be
     * applied leaves the image as it was.
     *
     * @param callable(array<array-key, mixed>): void $rememberCrop
     * @param callable(string): void                  $rememberPath
     */
    private function applyCrop(
        mixed $rectangle,
        ?string $source,
        int $minimumWidth,
        callable $rememberCrop,
        callable $rememberPath,
    ): bool {
        if (
            !is_array($rectangle)
            || null === $source
        ) {
            return true;
        }

        $cropped = $this->imageUploadService->applyCrop(
            $source,
            $rectangle,
            $minimumWidth,
        );
        if (null === $cropped) {
            return false;
        }

        $rememberCrop($rectangle);
        $rememberPath($cropped);

        return true;
    }
}
