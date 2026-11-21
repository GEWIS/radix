<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Database;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\ProspectiveMember;
use App\Entity\Database\RenewalLink;
use App\Repository\Database\ActionLinkRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function count;

final class ActionLinkRepositoryTest extends DatabaseTestCase
{
    private ActionLinkRepository $repository;

    private EntityManagerInterface $ledger;

    private LedgerBuilder $build;

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
        $this->repository = self::getContainer()->get(ActionLinkRepository::class);
        $this->build = new LedgerBuilder($ledger);
    }

    public function testAPaymentLinkIsFoundBySelectorAndProspectiveMember(): void
    {
        $prospective = $this->prospectiveMember();

        $link = new PaymentLink();
        $link->prospectiveMember = $prospective;
        $this->repository->persist($link);

        $bySelector = $this->repository->findPaymentBySelector($link->selector);

        self::assertNotNull($bySelector);
        self::assertSame(
            $link->id,
            $bySelector->id,
        );
        self::assertSame(
            $prospective->lidnr,
            $bySelector->prospectiveMember->lidnr,
        );

        $byMember = $this->repository->findPaymentByProspectiveMember($prospective->lidnr);

        self::assertSame(
            $link->id,
            $byMember?->id,
        );

        self::assertNull($this->repository->findPaymentBySelector('does-not-exist'));
        self::assertNull($this->repository->findPaymentByProspectiveMember(999999));
    }

    public function testFindByTempHashMatchesTheExchangedClaim(): void
    {
        $member = $this->build->member();
        $link = $this->repository->createRenewalByMember($member);
        self::assertNotNull($link);

        $link->tempHash = 'a-temporary-hash';
        $this->repository->persist($link);

        self::assertSame(
            $link->id,
            $this->repository->findByTempHash('a-temporary-hash')?->id,
        );
        self::assertNull($this->repository->findByTempHash('another-hash'));
    }

    public function testARenewalLinkIsFoundBySelectorAndByMember(): void
    {
        $member = $this->build->member();
        $link = $this->repository->createRenewalByMember(
            $member,
            new DateTimeImmutable('+2 years'),
        );
        self::assertNotNull($link);

        $bySelector = $this->repository->findRenewalBySelector($link->selector);

        self::assertNotNull($bySelector);
        self::assertSame(
            $member->lidnr,
            $bySelector->member->lidnr,
        );

        $byMember = $this->repository->findRenewalByMember($member->lidnr);

        self::assertNotNull($byMember);
        self::assertCount(
            1,
            $byMember,
        );
        self::assertSame(
            $link->id,
            $byMember[0]->id,
        );

        self::assertNull($this->repository->findRenewalBySelector('does-not-exist'));
    }

    public function testTheDefaultRenewalExpiresOnTheFirstOfJulyAndStaysWithinTheLimit(): void
    {
        $member = $this->build->member(
            MembershipTypes::Ordinary,
            '-1 year',
            '+2 months',
        );
        $current = $member->getExpiration();

        $link = $this->repository->createRenewalByMember($member);

        self::assertInstanceOf(
            RenewalLink::class,
            $link,
        );
        self::assertSame(
            '07-01',
            $link->newExpiration->format('m-d'),
        );
        self::assertGreaterThan(
            $current,
            $link->newExpiration,
        );
        self::assertLessThanOrEqual(
            397,
            $link->newExpiration->diff($current)->days,
        );
        self::assertSame(
            $current->format('Y-m-d'),
            $link->currentExpiration->format('Y-m-d'),
        );
    }

    public function testRemoveDeletesTheLink(): void
    {
        $member = $this->build->member();
        $link = $this->repository->createRenewalByMember(
            $member,
            new DateTimeImmutable('+2 years'),
        );
        self::assertNotNull($link);

        $selector = $link->selector;
        $this->repository->remove($link);

        self::assertNull($this->repository->findRenewalBySelector($selector));
    }

    private function prospectiveMember(): ProspectiveMember
    {
        $members = $this->ledger->getRepository(ProspectiveMember::class)->findAll();
        self::assertNotEmpty($members);
        self::assertGreaterThanOrEqual(
            1,
            count($members),
        );

        return $members[0];
    }
}
