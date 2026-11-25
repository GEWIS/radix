<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\User;

use App\Entity\Decision\AssociationYear;
use App\Entity\User\CompanyUser;
use App\Entity\User\Enums\SecurityEventType;
use App\Entity\User\SecurityLog;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

use function intval;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * On 1 July every remember-me cookie must stop working, and this run signs the whole association out. It updates one
 * column of every member account, records one row, and leaves company accounts alone unless asked.
 */
final class ForceReloginUsersCommandTest extends DatabaseTestCase
{
    private const string RELOGIN_AT = '2030-07-01 00:00:00';

    public function testEveryMemberAccountIsSignedOutAtTheGivenMoment(): void
    {
        $accounts = $this->countTable(User::class);

        $result = static::runCommand(
            'app:users:force-relogin',
            ['date' => '2030-07-01'],
        );
        $this->assertCommandIsSuccessful($result);

        $this->entityManager->clear();
        self::assertSame(
            $accounts,
            $this->countWithReloginAt(User::class),
            'Every account has to have to sign in again, not merely most of them.',
        );
        self::assertStringContainsString(
            'Updated forceReloginAt for ' . $accounts . ' users.',
            $result->getDisplay(),
        );
    }

    /**
     * One row for the run, because a row per member would put tens of thousands of entries in the table for one
     * administrative act.
     */
    public function testTheRunIsRecordedOnceWithWhatItDid(): void
    {
        $accounts = $this->countTable(User::class);
        $before = $this->countSecurityEvents(SecurityEventType::ReloginForced);

        $this->assertCommandIsSuccessful(static::runCommand('app:users:force-relogin', ['date' => '2030-07-01']));

        $this->entityManager->clear();
        $rows = $this->securityEvents(SecurityEventType::ReloginForced);
        self::assertCount(
            $before + 1,
            $rows,
        );

        $recorded = $rows[$before];
        self::assertSame(
            'main',
            $recorded['firewallname'],
        );
        self::assertSame(
            [
                'accounts' => $accounts,
                'forceReloginAt' => new DateTimeImmutable('2030-07-01')->format(DateTimeImmutable::ATOM),
            ],
            json_decode(
                (string) $recorded['detail'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * Company accounts have their own sessions. They are signed out only when asked, and then no member is signed
     * out instead.
     */
    public function testCompanyAccountsAreOnlySignedOutWhenAsked(): void
    {
        $companyAccounts = $this->countTable(CompanyUser::class);
        $memberAccounts = $this->countTable(User::class);

        $result = static::runCommand(
            'app:users:force-relogin',
            [
                'date' => '2030-07-01',
                '--company-users' => true,
            ],
        );
        $this->assertCommandIsSuccessful($result);

        $this->entityManager->clear();
        self::assertSame(
            $companyAccounts,
            $this->countWithReloginAt(CompanyUser::class),
        );
        self::assertSame(
            0,
            $this->countWithReloginAt(User::class),
            'A run for company accounts leaves the members alone.',
        );
        self::assertStringContainsString(
            'Updated forceReloginAt for ' . $companyAccounts . ' company users.',
            $result->getDisplay(),
        );
    }

    /**
     * Without an argument the date is the start of the association year, which the 1 July schedule relies on.
     */
    public function testTheDefaultIsTheStartOfTheAssociationYear(): void
    {
        $this->assertCommandIsSuccessful(static::runCommand('app:users:force-relogin'));

        $start = AssociationYear::fromDate(new DateTimeImmutable())->getStartDate();

        $this->entityManager->clear();
        self::assertSame(
            $this->countTable(User::class),
            intval($this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM ' . $this->entityManager->getClassMetadata(User::class)->getTableName()
                . ' WHERE forceReloginAt = ?',
                [$start->format('Y-m-d H:i:s')],
            )),
        );
    }

    /**
     * An argument that is not a date is refused; "yesterday" parses as a date and would sign everybody out at a
     * moment nobody chose.
     */
    public function testAnUnparseableDateUpdatesNothing(): void
    {
        $before = $this->countSecurityEvents(SecurityEventType::ReloginForced);

        $result = static::runCommand(
            'app:users:force-relogin',
            ['date' => 'sometime next year'],
        );
        $this->assertCommandIsInvalid($result);

        $this->entityManager->clear();
        self::assertSame(
            $before,
            $this->countSecurityEvents(SecurityEventType::ReloginForced),
            'A refused run is not something that happened to anybody.',
        );
        self::assertStringContainsString(
            'Invalid date format',
            $result->getDisplay(),
        );
    }

    /**
     * @param class-string $entity
     */
    private function countTable(string $entity): int
    {
        return intval($this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->entityManager->getClassMetadata($entity)->getTableName(),
        ));
    }

    /**
     * @param class-string $entity
     */
    private function countWithReloginAt(string $entity): int
    {
        return intval($this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->entityManager->getClassMetadata($entity)->getTableName()
            . ' WHERE forceReloginAt = ?',
            [self::RELOGIN_AT],
        ));
    }

    private function countSecurityEvents(SecurityEventType $event): int
    {
        return intval($this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->entityManager->getClassMetadata(SecurityLog::class)->getTableName()
            . ' WHERE event = ?',
            [$event->value],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function securityEvents(SecurityEventType $event): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT * FROM ' . $this->entityManager->getClassMetadata(SecurityLog::class)->getTableName()
            . ' WHERE event = ? ORDER BY id',
            [$event->value],
        );

        return $rows;
    }
}
