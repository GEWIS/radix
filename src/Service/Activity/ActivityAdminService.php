<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Service\Application\EditLockService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writing an activity from the admin screens, and the four standing decisions that can be taken about one that is
 * already out: cancelling it, taking that back, unpublishing it and putting it back up.
 *
 * Saving an edit and letting go of the edit lock are one operation rather than two: a save that commits but leaves the
 * lock standing blocks the author out of their own draft until the lock's TTL lapses.
 */
final readonly class ActivityAdminService
{
    public function __construct(
        private readonly ActivityFacilityNotifier $facilityNotifier,
        private EditLockService $editLockService,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * A brand-new activity and its first revision, which rides along: Activity::$revisions cascades persist.
     */
    public function create(Activity $activity): void
    {
        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        $revision = $activity->getCurrentRevision();
        if (null === $revision) {
            return;
        }

        $this->facilityNotifier->created($revision);
    }

    /**
     * Claim the draft at the version it was opened at, so an edit made against a copy somebody else has since changed
     * is refused rather than silently overwriting theirs. A draft that was only just spawned has nothing to race, so
     * there is nothing to claim.
     *
     * @throws OptimisticLockException when it was changed elsewhere in the meantime.
     */
    public function claimVersion(
        ActivityRevision $revision,
        int $baseVersion,
    ): void {
        if (null === $revision->getId()) {
            return;
        }

        $this->entityManager->lock(
            $revision,
            LockMode::OPTIMISTIC,
            $baseVersion,
        );
    }

    public function saveDraft(
        Activity $activity,
        ActivityRevision $revision,
        User $user,
    ): void {
        $revision->setLastEditedBy($user);

        $this->entityManager->persist($activity);
        $this->entityManager->persist($revision);
        // Read before the flush, which is what lets a facility that was just asked for be told apart from one that
        // was already on the draft.
        $this->facilityNotifier->draftSaved($revision);
        $this->entityManager->flush();

        $this->editLockService->release(
            $activity,
            $user,
        );
    }

    public function cancel(
        Activity $activity,
        Member $member,
    ): void {
        $activity->cancel($member);

        $this->entityManager->flush();
    }

    public function uncancel(Activity $activity): void
    {
        $activity->uncancel();

        $this->entityManager->flush();
    }

    public function unpublish(
        Activity $activity,
        Member $member,
    ): void {
        $activity->unpublish($member);

        $this->entityManager->flush();
    }

    public function republish(Activity $activity): void
    {
        $activity->republish();

        $this->entityManager->flush();
    }
}
