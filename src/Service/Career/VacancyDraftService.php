<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Entity\User\CompanyUser;
use App\Entity\User\User;
use App\Service\Application\EditLockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writing a vacancy's draft, from either surface: the committee's admin screens and the company's own portal both
 * reach this, because what is saved and in what order does not depend on who is looking at it.
 *
 * Saving an edit and letting go of the edit lock are one operation rather than two: a save that commits but leaves the
 * lock standing blocks the author out of their own draft until the lock's TTL lapses.
 */
final readonly class VacancyDraftService
{
    public function __construct(
        private EditLockService $editLockService,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * A brand-new vacancy and the first draft of it, which only exist together.
     */
    public function createDraft(
        Vacancy $vacancy,
        VacancyRevision $revision,
    ): void {
        $this->entityManager->persist($vacancy);
        $this->entityManager->persist($revision);
        $this->entityManager->flush();
    }

    public function saveDraft(
        Vacancy $vacancy,
        VacancyRevision $draft,
        User|CompanyUser $actor,
    ): void {
        if ($actor instanceof User) {
            $draft->setLastEditedBy($actor);
        } else {
            $draft->setLastEditedByCompanyUser($actor);
        }

        $this->entityManager->flush();

        // Releasing a lock the actor does not hold does nothing, so this is safe on the surfaces that never took one.
        $this->editLockService->release(
            $vacancy,
            $actor,
        );
    }
}
