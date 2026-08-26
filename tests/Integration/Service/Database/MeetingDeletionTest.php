<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Database;

use App\Entity\Database\Enums\InstallationFunctions;
use App\Exception\Database\DecisionNamesDeletedMember;
use App\Service\Database\Meeting;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What removing a decision from the ledger is allowed to take with it.
 *
 * Every write is rolled back by dama/doctrine-test-bundle, so the seed these decisions are added to survives the run.
 */
#[CoversClass(Meeting::class)]
class MeetingDeletionTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Meeting $meetingService;
    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->meetingService = self::getContainer()->get(Meeting::class);
        $this->build = new LedgerBuilder($this->entityManager);
    }

    /**
     * A deleted member's record is kept for the decisions that name them, so removing one of those decisions is a
     * correction to be made deliberately rather than the side effect of tidying a meeting.
     */
    public function testRefusesToDeleteADecisionThatNamesADeletedMember(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan($meeting);

        $member = $this->build->member();
        $member->setDeleted(true);

        $installation = $this->build->install(
            $this->build->meeting(date: '2026-10-01'),
            $foundation,
            $member,
            InstallationFunctions::Chair,
        );
        $this->entityManager->flush();

        $decision = $installation->getDecision();

        $this->expectException(DecisionNamesDeletedMember::class);

        $this->meetingService->deleteDecision(
            $decision->getMeetingType(),
            $decision->getMeetingNumber(),
            $decision->getPoint(),
            $decision->getNumber(),
        );
    }

    /**
     * The same decision comes out again as soon as the member it names is on the record like any other.
     */
    public function testDeletesADecisionThatNamesAMemberWhoIsNotDeleted(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan($meeting);

        $installation = $this->build->install(
            $this->build->meeting(date: '2026-10-01'),
            $foundation,
            $this->build->member(),
            InstallationFunctions::Chair,
        );

        $decision = $installation->getDecision();

        self::assertTrue($this->meetingService->deleteDecision(
            $decision->getMeetingType(),
            $decision->getMeetingNumber(),
            $decision->getPoint(),
            $decision->getNumber(),
        ));
    }
}
