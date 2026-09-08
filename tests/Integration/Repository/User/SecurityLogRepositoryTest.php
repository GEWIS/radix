<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\User;

use App\Entity\User\Enums\SecurityEventCategory;
use App\Entity\User\Enums\SecurityEventType;
use App\Entity\User\SecurityLog;
use App\Repository\User\SecurityLogRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

use function iterator_to_array;

final class SecurityLogRepositoryTest extends DatabaseTestCase
{
    private const string USER = '8000';

    private const string OTHER_USER = '8001';

    private const string ADDRESS = '192.0.2.10';

    public function testTheAdminOverviewFindsAnAccountItsActorAndItsAddress(): void
    {
        $repository = $this->repository();

        $this->append(
            $repository,
            SecurityEventType::SignInSucceeded,
            self::USER,
        );
        $this->append(
            $repository,
            SecurityEventType::SessionTerminated,
            self::OTHER_USER,
            actor: self::USER,
        );

        // The account it happened to, and the account that did it, both answer to the same search.
        self::assertCount(
            2,
            $this->search(
                $repository,
                self::USER,
            ),
        );
        self::assertCount(
            2,
            $this->search(
                $repository,
                self::ADDRESS,
            ),
        );
        self::assertCount(
            1,
            $this->search(
                $repository,
                self::OTHER_USER,
            ),
        );
    }

    /**
     * A category is a set of event types, resolved before the query so the column stays a plain string the index can
     * answer on.
     */
    public function testTheOverviewNarrowsByCategory(): void
    {
        $repository = $this->repository();

        $this->append(
            $repository,
            SecurityEventType::SignInSucceeded,
            self::USER,
        );
        $this->append(
            $repository,
            SecurityEventType::PasswordChanged,
            self::USER,
        );

        $found = $this->search(
            $repository,
            self::USER,
            SecurityEventCategory::Password,
        );

        self::assertCount(
            1,
            $found,
        );
        self::assertSame(
            SecurityEventType::PasswordChanged,
            $found[0]->getEvent(),
        );
    }

    public function testEventsAreListedNewestFirst(): void
    {
        $repository = $this->repository();

        $this->append(
            $repository,
            SecurityEventType::SignInSucceeded,
            self::USER,
            occurredAt: new DateTimeImmutable('-2 hours'),
        );
        $this->append(
            $repository,
            SecurityEventType::SignedOut,
            self::USER,
            occurredAt: new DateTimeImmutable('-1 hour'),
        );

        $entries = $repository->findAllByUser(self::USER);

        self::assertSame(
            SecurityEventType::SignedOut,
            $entries[0]->getEvent(),
        );
    }

    /**
     * The one thing keeping this table from growing without end, and the reason it may keep as much per row as it
     * does.
     */
    public function testPruningForgetsOnlyWhatIsPastTheRetentionPeriod(): void
    {
        $repository = $this->repository();

        $this->append(
            $repository,
            SecurityEventType::SignInSucceeded,
            self::USER,
            occurredAt: new DateTimeImmutable('-200 days'),
        );
        $this->append(
            $repository,
            SecurityEventType::SignInSucceeded,
            self::USER,
            occurredAt: new DateTimeImmutable('-1 day'),
        );

        self::assertSame(
            1,
            $repository->deleteOccurredBefore(new DateTimeImmutable('-180 days')),
        );
        self::assertCount(
            1,
            $repository->findAllByUser(self::USER),
        );
    }

    /**
     * An erasure request drops what the account was the subject of. What it was the actor of belongs to the accounts
     * it was done to, who are still entitled to know it happened.
     */
    public function testForgettingAnAccountLeavesWhatItDidToOthers(): void
    {
        $repository = $this->repository();

        $this->append(
            $repository,
            SecurityEventType::SignInSucceeded,
            self::USER,
        );
        $this->append(
            $repository,
            SecurityEventType::SessionTerminated,
            self::OTHER_USER,
            actor: self::USER,
        );

        self::assertSame(
            1,
            $repository->deleteAllForUser(self::USER),
        );
        self::assertSame(
            [],
            $repository->findAllByUser(self::USER),
        );
        self::assertCount(
            1,
            $repository->findAllByUser(self::OTHER_USER),
        );
    }

    private function append(
        SecurityLogRepository $repository,
        SecurityEventType $event,
        string $userIdentifier,
        ?string $actor = null,
        ?DateTimeImmutable $occurredAt = null,
    ): void {
        $log = new SecurityLog();
        $log->setOccurredAt($occurredAt ?? new DateTimeImmutable());
        $log->setEvent($event);
        $log->setUserIdentifier($userIdentifier);
        $log->setFirewallName('main');
        $log->setActorIdentifier($actor);
        $log->setIpAddress(self::ADDRESS);

        $repository->append($log);
    }

    /**
     * @return list<SecurityLog>
     */
    private function search(
        SecurityLogRepository $repository,
        string $search,
        ?SecurityEventCategory $category = null,
    ): array {
        return iterator_to_array(
            $repository->paginateForAdmin(
                search: $search,
                category: $category,
                events: [],
                since: null,
                page: 1,
                pageSize: 25,
            )->getIterator(),
            false,
        );
    }

    private function repository(): SecurityLogRepository
    {
        $repository = self::getContainer()->get(SecurityLogRepository::class);
        self::assertInstanceOf(
            SecurityLogRepository::class,
            $repository,
        );

        return $repository;
    }
}
