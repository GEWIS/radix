<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupFieldValue;
use App\Entity\Activity\SignupList;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;

use function count;
use function intval;

/**
 * Sign-ups are kept for five years: they record who was where, which is personal data once the activity is long
 * past. The activity's end of schedule ages them, so a list whose evening has not happened is never removed.
 */
final class DeleteOldSignupsCommandTest extends DatabaseTestCase
{
    public function testSignupsForAnActivityLongPastAreDeletedWithTheirAnswers(): void
    {
        $old = $this->listWithSignups();
        $oldId = (int) $old->id;
        $signups = $this->signupIdsOn($old);
        self::assertNotSame(
            [],
            $signups,
            'The seed is expected to contain a sign-up list with sign-ups on it.',
        );

        $kept = $this->anotherListWithSignups($old);
        $keptSignups = $this->signupIdsOn($kept);

        // Six years past its end: the only way to age a sign-up is the revision that says when the activity ran.
        $this->ageActivity(
            $old,
            '-6 years',
        );

        $result = static::runCommand('app:activity:delete-old-signups');
        $this->assertCommandIsSuccessful($result);

        $this->entityManager->clear();
        foreach ($signups as $signupId) {
            self::assertNull(
                $this->entityManager->getRepository(Signup::class)->find($signupId),
                'A sign-up for an activity six years past has no reason to be kept.',
            );
            self::assertSame(
                0,
                $this->fieldValuesFor($signupId),
                'An answer would be unreachable without its sign-up.',
            );
        }

        foreach ($keptSignups as $signupId) {
            self::assertNotNull(
                $this->entityManager->getRepository(Signup::class)->find($signupId),
                'A list for an activity that has not happened yet is not in reach.',
            );
        }

        self::assertStringContainsString(
            'Deleted ' . count($signups) . ' sign-up(s)',
            $result->getDisplay(),
        );
    }

    public function testNothingIsDeletedWhenEveryActivityIsRecent(): void
    {
        $signups = $this->signupIdsOn($this->listWithSignups());

        $result = static::runCommand('app:activity:delete-old-signups');
        $this->assertCommandIsSuccessful($result);

        $this->entityManager->clear();
        foreach ($signups as $signupId) {
            self::assertNotNull(
                $this->entityManager->getRepository(Signup::class)->find($signupId),
            );
        }

        self::assertStringContainsString(
            'Deleted 0 sign-up(s)',
            $result->getDisplay(),
        );
    }

    /**
     * The list whose revision is aged; the sign-ups belong to the live revision, where the command reads the schedule.
     */
    private function listWithSignups(): SignupList
    {
        $list = $this->entityManager->createQueryBuilder()
            ->select('sl')
            ->from(
                SignupList::class,
                'sl',
            )
            ->join(
                'sl.revision',
                'r',
            )
            ->where('SIZE(sl.signUps) > 0')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            SignupList::class,
            $list,
            'The seed is expected to contain a sign-up list with sign-ups on it.',
        );

        return $list;
    }

    private function anotherListWithSignups(SignupList $other): SignupList
    {
        $list = $this->entityManager->createQueryBuilder()
            ->select('sl')
            ->from(
                SignupList::class,
                'sl',
            )
            ->where('SIZE(sl.signUps) > 0')
            ->andWhere('sl.id != :other')
            ->setParameter(
                'other',
                $other->id,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            SignupList::class,
            $list,
            'The seed is expected to contain a second sign-up list, so "not deleted" has a subject.',
        );

        return $list;
    }

    /**
     * @return list<int>
     */
    private function signupIdsOn(SignupList $list): array
    {
        $ids = [];
        foreach (
            $this->entityManager->createQueryBuilder()
            ->select('s.id')
            ->from(
                Signup::class,
                's',
            )
            ->where('s.signupList = :list')
            ->setParameter(
                'list',
                $list->id,
            )
            ->getQuery()
            ->getSingleColumnResult() as $id
        ) {
            $ids[] = intval($id);
        }

        return $ids;
    }

    private function fieldValuesFor(int $signupId): int
    {
        return intval($this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->entityManager->getClassMetadata(SignupFieldValue::class)->getTableName()
            . ' WHERE signup_id = ?',
            [$signupId],
        ));
    }

    private function ageActivity(
        SignupList $list,
        string $endTime,
    ): void {
        $revision = $list->revision;

        $this->entityManager->createQueryBuilder()
            ->update(
                ActivityRevision::class,
                'r',
            )
            ->set(
                'r.beginTime',
                ':begin',
            )
            ->set(
                'r.endTime',
                ':end',
            )
            ->where('r.id = :id')
            ->setParameter(
                'begin',
                new DateTimeImmutable($endTime),
                Types::DATETIME_IMMUTABLE,
            )
            ->setParameter(
                'end',
                new DateTimeImmutable($endTime . ' +1 hour'),
                Types::DATETIME_IMMUTABLE,
            )
            ->setParameter(
                'id',
                $revision->id,
            )
            ->getQuery()
            ->execute();
    }
}
