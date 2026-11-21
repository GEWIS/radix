<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Database;

use App\Entity\Database\CheckoutSession;
use App\Entity\Database\Enums\CheckoutSessionStates;
use App\Entity\Database\ProspectiveMember;
use App\Repository\Database\CheckoutSessionRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function count;

final class CheckoutSessionRepositoryTest extends DatabaseTestCase
{
    private CheckoutSessionRepository $repository;

    private EntityManagerInterface $ledger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->ledger = $ledger;
        $this->repository = self::getContainer()->get(CheckoutSessionRepository::class);
    }

    public function testFindByIdMatchesTheStripeIdentifier(): void
    {
        $found = $this->repository->findById('123');

        self::assertNotNull($found);
        self::assertSame(
            '123',
            $found->checkoutId,
        );
        self::assertNull($this->repository->findById('no-such-session'));
    }

    public function testFindLatestReturnsTheNewestSessionOfTheApplicant(): void
    {
        $member = $this->prospectiveMember();

        $older = $this->session(
            $member,
            CheckoutSessionStates::Expired,
        );
        $newer = $this->session(
            $member,
            CheckoutSessionStates::Paid,
        );

        self::assertGreaterThan(
            $older->id,
            $newer->id,
        );

        $latest = $this->repository->findLatest($member);

        self::assertNotNull($latest);
        self::assertSame(
            $newer->id,
            $latest->id,
        );
    }

    private function session(
        ProspectiveMember $member,
        CheckoutSessionStates $state,
    ): CheckoutSession {
        $session = new CheckoutSession();
        $session->checkoutId = 'checkout-' . $member->lidnr . '-' . count(
            $this->repository->findBy(['prospectiveMember' => $member]),
        );
        $session->prospectiveMember = $member;
        $session->created = new DateTimeImmutable();
        $session->expiration = new DateTimeImmutable('+1 hour');
        $session->state = $state;

        $this->repository->persist($session);

        return $session;
    }

    private function prospectiveMember(): ProspectiveMember
    {
        $members = $this->ledger->getRepository(ProspectiveMember::class)->findAll();
        self::assertNotEmpty($members);

        return $members[0];
    }
}
