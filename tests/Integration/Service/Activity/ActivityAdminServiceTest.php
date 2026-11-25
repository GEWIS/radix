<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\SignupList;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Service\Activity\ActivityAdminService;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\OptimisticLockException;

/**
 * Writing an activity and the four standing decisions about one that is already published. An edit is claimed at the
 * version it was opened at, so a save against a copy someone else changed is refused.
 */
final class ActivityAdminServiceTest extends DatabaseTestCase
{
    public function testASignUpListIsPersistedStraightAwayAndHangsOffTheRevision(): void
    {
        $revision = $this->revision();

        $list = $this->service()->addSignupList($revision);

        self::assertNotNull($list->id);
        self::assertSame(
            $revision,
            $list->revision,
        );
        self::assertTrue($revision->getSignupLists()->contains($list));
    }

    public function testASignUpListWithNothingUnderItCanBeRemoved(): void
    {
        $revision = $this->revision();
        $list = $this->service()->addSignupList($revision);
        $id = (int) $list->id;

        self::assertTrue($this->service()->removeSignupList($list));

        self::assertNull($this->entityManager->getRepository(SignupList::class)->find($id));
        self::assertFalse($revision->getSignupLists()->contains($list));
    }

    /**
     * A list with sign-ups is the one thing this cannot discard; the organiser withdraws it through the sign-ups
     * instead.
     */
    public function testASignUpListWithSignUpsIsKept(): void
    {
        $list = $this->listWithSignUps();

        self::assertFalse($this->service()->removeSignupList($list));
        self::assertNotNull($this->entityManager->getRepository(SignupList::class)->find($list->id));
    }

    public function testSavingTheListsOfARevisionCommitsThem(): void
    {
        $revision = $this->revision();
        $list = $this->service()->addSignupList($revision);

        $this->service()->saveSignupLists($revision);

        self::assertNotNull($list->id);
    }

    /**
     * A draft opened at a version somebody else has since changed is refused rather than overwriting their edit.
     */
    public function testADraftChangedElsewhereCannotBeClaimed(): void
    {
        $revision = $this->revision();

        $this->expectException(OptimisticLockException::class);
        $this->service()->claimVersion(
            $revision,
            $this->versionOf($revision) + 1,
        );
    }

    public function testSavingADraftRecordsTheEditor(): void
    {
        $activity = $this->activity();
        $revision = $this->revision();
        $user = $this->user();

        $this->service()->saveDraft(
            $activity,
            $revision,
            $user,
        );

        self::assertSame(
            $user,
            $revision->lastEditedBy,
        );
    }

    public function testCancellingAnActivityRecordsWhoDidIt(): void
    {
        $activity = $this->activity();
        $member = $this->member();

        $this->service()->cancel(
            $activity,
            $member,
        );

        self::assertTrue($activity->isCancelled());
    }

    public function testUncancellingAnActivityClearsIt(): void
    {
        $activity = $this->activity();
        $this->service()->cancel(
            $activity,
            $this->member(),
        );

        $this->service()->uncancel($activity);

        self::assertFalse($activity->isCancelled());
    }

    public function testAnActivityCanBeTakenOffTheSiteAndPutBack(): void
    {
        $activity = $this->activity();

        $this->service()->unpublish(
            $activity,
            $this->member(),
        );
        self::assertTrue($activity->isUnpublished());

        $this->service()->republish($activity);
        self::assertFalse($activity->isUnpublished());
    }

    private function service(): ActivityAdminService
    {
        return self::getContainer()->get(ActivityAdminService::class);
    }

    private function activity(): Activity
    {
        $activity = $this->entityManager->getRepository(Activity::class)->findOneBy([]);
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        return $activity;
    }

    private function revision(): ActivityRevision
    {
        $revision = $this->entityManager->getRepository(ActivityRevision::class)->findOneBy([]);
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );

        return $revision;
    }

    private function listWithSignUps(): SignupList
    {
        foreach ($this->entityManager->getRepository(SignupList::class)->findAll() as $list) {
            if ($list->hasSignUps()) {
                return $list;
            }
        }

        self::fail('The seed is expected to contain a sign-up list with sign-ups.');
    }

    private function versionOf(ActivityRevision $revision): int
    {
        $table = $this->entityManager->getClassMetadata(ActivityRevision::class)->getTableName();

        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT version FROM ' . $table . ' WHERE id = ?',
            [$revision->id],
        );
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8025);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function member(): Member
    {
        $member = $this->entityManager->getRepository(Member::class)->find(8025);
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }
}
