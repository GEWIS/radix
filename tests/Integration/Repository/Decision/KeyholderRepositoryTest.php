<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Decision;

use App\Entity\Database\SubDecision\Key\Granting as LedgerGranting;
use App\Entity\Decision\Keyholder;
use App\Entity\Decision\SubDecision\Key\Granting;
use App\Repository\Decision\KeyholderRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function iterator_to_array;
use function sprintf;

final class KeyholderRepositoryTest extends DatabaseTestCase
{
    private KeyholderRepository $repository;

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
        $this->repository = self::getContainer()->get(KeyholderRepository::class);
        $this->build = new LedgerBuilder($ledger);
    }

    public function testCurrentKeyholdersAreOrderedByMemberNumber(): void
    {
        $page = iterator_to_array(
            $this->repository->paginateKeyholders(
                false,
                false,
                1,
                5000,
            ),
            false,
        );

        self::assertNotEmpty($page);

        $today = new DateTimeImmutable('today');
        $previous = 0;
        foreach ($page as $keyholder) {
            self::assertGreaterThanOrEqual(
                $today,
                $keyholder->expirationDate,
            );
            self::assertTrue(
                null === $keyholder->withdrawnDate || $keyholder->withdrawnDate >= $today,
            );
            self::assertFalse($keyholder->member->deleted);
            self::assertLessThanOrEqual(
                $keyholder->member->lidnr,
                $previous,
            );
            $previous = $keyholder->member->lidnr;
        }
    }

    public function testAKeyThatRunsOutOrIsWithdrawnOnlyAppearsWhenExpiredOnesAreIncluded(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $current = $this->build->grantKey(
            $meeting,
            $member,
        );
        $expired = $this->build->grantKey(
            $meeting,
            $member,
            '-1 month',
        );
        $withdrawn = $this->build->grantKey(
            $meeting,
            $member,
        );
        $this->build->withdrawKey(
            $meeting,
            $withdrawn,
            '-1 day',
        );

        $currentDecisions = $this->decisionHashes($this->repository->paginateKeyholders(
            false,
            false,
            1,
            5000,
        ));
        $allDecisions = $this->decisionHashes($this->repository->paginateKeyholders(
            true,
            false,
            1,
            5000,
        ));

        self::assertContains(
            $this->grantKeyOf($current),
            $currentDecisions,
        );
        self::assertNotContains(
            $this->grantKeyOf($expired),
            $currentDecisions,
        );
        self::assertNotContains(
            $this->grantKeyOf($withdrawn),
            $currentDecisions,
        );
        self::assertContains(
            $this->grantKeyOf($expired),
            $allDecisions,
        );
        self::assertContains(
            $this->grantKeyOf($withdrawn),
            $allDecisions,
        );
    }

    public function testAKeyThatExpiresTodayStillCountsAsCurrent(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $today = $this->build->grantKey(
            $meeting,
            $member,
            'today',
        );

        self::assertContains(
            $this->grantKeyOf($today),
            $this->decisionHashes($this->repository->paginateKeyholders(
                false,
                false,
                1,
                5000,
            )),
        );
    }

    public function testADeletedKeyholderOnlyAppearsWhenDeletedMembersAreIncluded(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $this->build->grantKey(
            $meeting,
            $member,
        );
        $member->deleted = true;
        $this->ledger->flush();

        self::assertNotContains(
            $member->lidnr,
            $this->memberNumbers($this->repository->paginateKeyholders(
                false,
                false,
                1,
                5000,
            )),
        );
        self::assertContains(
            $member->lidnr,
            $this->memberNumbers($this->repository->paginateKeyholders(
                false,
                true,
                1,
                5000,
            )),
        );
    }

    public function testThePageSizeIsApplied(): void
    {
        self::assertCount(
            2,
            iterator_to_array($this->repository->paginateKeyholders(
                true,
                true,
                1,
                2,
            )),
        );
    }

    /**
     * The identity of a granting, as the ledger and the projection both define it.
     */
    private function grantKeyOf(LedgerGranting|Granting $granting): string
    {
        return sprintf(
            '%s %d.%d.%d.%d',
            $granting->getMeetingType()->value,
            $granting->getMeetingNumber(),
            $granting->getDecisionPoint(),
            $granting->getDecisionNumber(),
            $granting->sequence,
        );
    }

    /**
     * @param iterable<Keyholder> $page
     *
     * @return list<string>
     */
    private function decisionHashes(iterable $page): array
    {
        $hashes = [];
        foreach ($page as $keyholder) {
            $hashes[] = $this->grantKeyOf($keyholder->grantingDec);
        }

        return $hashes;
    }

    /**
     * @param iterable<Keyholder> $page
     *
     * @return list<int>
     */
    private function memberNumbers(iterable $page): array
    {
        $numbers = [];
        foreach ($page as $keyholder) {
            $numbers[] = $keyholder->member->lidnr;
        }

        return $numbers;
    }
}
