<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Entity\Career\Enums\CompanyAuditVerbs;
use App\Entity\User\CompanyUser;
use App\Entity\User\User;
use App\Service\Application\EditLockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Writing a company's draft, from either surface: the committee's admin screens and the company's own portal both
 * reach this, because what is saved and in what order does not depend on who is looking at it.
 *
 * Storing the logos, stamping who edited, committing and letting go of the edit lock are one operation. They only look
 * like four because they used to be sequenced by a controller: a save that commits but leaves the lock standing blocks
 * the author out of their own draft until the lock's TTL lapses.
 *
 * A logo that could not be stored does not cost the author their text. The rest of the edit is saved and the previous
 * logo stays in use, which is what these methods report back so they can be told.
 */
final readonly class CompanyDraftService
{
    public function __construct(
        private CompanyAuditLogger $auditLogger,
        private CompanyImageUploadService $imageUploadService,
        private EditLockService $editLockService,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * A brand-new company and the first draft of it, which only exist together.
     *
     * Committed twice on purpose: the logos are stored under a directory named after the company's id, so the row has
     * to exist before there is anywhere to put them.
     */
    public function create(
        Company $company,
        CompanyRevision $revision,
        User $actor,
        ?UploadedFile $squareLogo,
        ?UploadedFile $bannerLogo,
    ): bool {
        $this->entityManager->persist($company);
        $this->entityManager->persist($revision);
        $this->entityManager->flush();

        $stored = $this->applyLogos(
            $company,
            $revision,
            $actor,
            $squareLogo,
            $bannerLogo,
        );

        $this->auditLogger->log(
            $company,
            $actor,
            CompanyAuditVerbs::CompanyCreated,
            $company->getName(),
        );

        $this->entityManager->flush();

        return $stored;
    }

    /**
     * Save an edit to a draft. Reports whether every logo it was handed was stored; false means at least one was
     * refused and the profile still shows the previous one.
     */
    public function saveDraft(
        Company $company,
        CompanyRevision $draft,
        User|CompanyUser $actor,
        ?UploadedFile $squareLogo,
        ?UploadedFile $bannerLogo,
    ): bool {
        $stored = $this->applyLogos(
            $company,
            $draft,
            $actor,
            $squareLogo,
            $bannerLogo,
        );

        if ($actor instanceof User) {
            $draft->setLastEditedBy($actor);
        } else {
            $draft->setLastEditedByCompanyUser($actor);
        }

        $this->entityManager->flush();

        // Releasing a lock the actor does not hold does nothing, so this is safe on the surfaces that never took one.
        $this->editLockService->release(
            $company,
            $actor,
        );

        return $stored;
    }

    /**
     * Put whichever logos were handed in onto the draft, leaving the previous one in place where an upload was
     * refused. Reports whether all of them were kept.
     */
    private function applyLogos(
        Company $company,
        CompanyRevision $revision,
        User|CompanyUser $actor,
        ?UploadedFile $squareLogo,
        ?UploadedFile $bannerLogo,
    ): bool {
        $stored = true;

        if (null !== $squareLogo) {
            $path = $this->storeLogo(
                $company,
                $actor,
                $squareLogo,
            );

            if (null === $path) {
                $stored = false;
            } else {
                $revision->setSquareLogo($path);
            }
        }

        if (null !== $bannerLogo) {
            $path = $this->storeLogo(
                $company,
                $actor,
                $bannerLogo,
            );

            if (null === $path) {
                $stored = false;
            } else {
                $revision->setBannerLogo($path);
            }
        }

        return $stored;
    }

    /**
     * The stored path of an uploaded logo, or null when it was refused. The audit entry is only scheduled here; it
     * commits with the save it belongs to.
     */
    private function storeLogo(
        Company $company,
        User|CompanyUser $actor,
        UploadedFile $file,
    ): ?string {
        $path = $this->imageUploadService->uploadLogo(
            $company,
            $file,
        );

        if (null === $path) {
            return null;
        }

        $this->auditLogger->log(
            $company,
            $actor,
            CompanyAuditVerbs::LogoUploaded,
        );

        return $path;
    }
}
