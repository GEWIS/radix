<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Application\Enums\NotificationType;
use App\Entity\Career\CompanyBannerPackage;
use App\Entity\Career\CompanyJobPackage;
use App\Entity\Career\CompanyPackage;
use App\Entity\Career\Enums\CompanyAuditVerbs;
use App\Entity\Career\Vacancy;
use App\Entity\User\User;
use App\Repository\Application\NotificationRepository;
use App\Service\Application\EditLockService;
use App\Service\Application\FileStorage;
use App\Service\Application\RevisionDiscarder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adding, changing and taking away the packages a company has bought, and settling a banner once it has been decided
 * on.
 *
 * Every one of these carries its own audit entry, and the entry commits with the change it describes: a timeline that
 * disagrees with what happened is worse than one that is missing.
 */
final readonly class CompanyPackageService
{
    public function __construct(
        private CompanyAuditLogger $auditLogger,
        private EditLockService $editLockService,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private FileStorage $fileStorage,
        private NotificationRepository $notificationRepository,
        private RevisionDiscarder $revisionDiscarder,
    ) {
    }

    public function create(
        CompanyPackage $package,
        User $actor,
    ): void {
        $this->entityManager->persist($package);

        $this->auditLogger->log(
            $package->getCompany(),
            $actor,
            CompanyAuditVerbs::PackageCreated,
            $package->getType()->value,
        );

        $this->entityManager->flush();
    }

    public function save(
        CompanyPackage $package,
        User $actor,
    ): void {
        $this->auditLogger->log(
            $package->getCompany(),
            $actor,
            CompanyAuditVerbs::PackageUpdated,
            $package->getType()->value,
        );

        $this->entityManager->flush();
    }

    public function delete(
        CompanyPackage $package,
        User $actor,
    ): void {
        $this->auditLogger->log(
            $package->getCompany(),
            $actor,
            CompanyAuditVerbs::PackageDeleted,
            $package->getType()->value,
        );

        // The vacancies go with the package, but their revision chains do not follow on their own: the review
        // comments and the previous-revision links have no cascade, and a vacancy points back at the revision it
        // shows. Those references are dropped first, in their own flush, so the removals that follow are unambiguous;
        // one transaction, so a crash in between cannot leave a vacancy without its chain.
        $this->entityManager->wrapInTransaction(function () use ($package): void {
            if ($package instanceof CompanyJobPackage) {
                foreach ($package->getVacancies() as $vacancy) {
                    $this->unhookVacancy($vacancy);
                }

                $this->entityManager->flush();

                foreach ($package->getVacancies() as $vacancy) {
                    foreach ($vacancy->getRevisions() as $revision) {
                        $this->revisionDiscarder->removeRevision($revision);
                    }
                }
            }

            $this->entityManager->remove($package);
            $this->entityManager->flush();
        });
    }

    /**
     * What both decisions do once the banner itself has been settled: record who decided, reclaim the image nothing
     * points at any more, and take the queue notification down so the company's next proposal is announced again.
     */
    public function settleBanner(
        CompanyBannerPackage $banner,
        User $actor,
        CompanyAuditVerbs $verb,
        ?string $discardedImage,
    ): void {
        $this->auditLogger->log(
            $banner->getCompany(),
            $actor,
            $verb,
        );

        $this->entityManager->flush();

        if (null !== $discardedImage) {
            $this->fileStorage->remove($discardedImage);
        }

        $id = $banner->getId();
        if (null === $id) {
            return;
        }

        $this->notificationRepository->removeForSubject(
            NotificationType::CompanyBannerAwaitingReview,
            $id,
        );
    }

    /**
     * Drops everything that would keep a vacancy's rows in place: its edit lock (which has no foreign key of its own),
     * the revision it shows and the links between the revisions in its chain.
     */
    private function unhookVacancy(Vacancy $vacancy): void
    {
        $this->editLockService->purge($vacancy);

        $vacancy->setCurrentRevision(null);
        $vacancy->setLiveRevision(null);

        foreach ($vacancy->getRevisions() as $revision) {
            $revision->setPreviousRevision(null);
        }
    }
}
