<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Entity\Activity\ActivityProposal;
use App\Entity\Activity\Enums\ProposalStatus;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\CompanyRevision;
use App\Workflow\ProposalStatusMarkingStore;
use App\Workflow\RevisionStatusMarkingStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Marking;

/**
 * The workflow place names are exactly the enum's backing values, so the mapping is a direct conversion in both
 * directions and cannot drift from what the database stores.
 */
final class MarkingStoreTest extends TestCase
{
    public function testAProposalsStatusIsItsMarking(): void
    {
        $proposal = new ActivityProposal();
        $proposal->status = ProposalStatus::Scheduled;

        $marking = new ProposalStatusMarkingStore()->getMarking($proposal);

        self::assertSame(
            [ProposalStatus::Scheduled->value => 1],
            $marking->getPlaces(),
        );
    }

    public function testAProposalsMarkingIsItsStatus(): void
    {
        $proposal = new ActivityProposal();

        new ProposalStatusMarkingStore()->setMarking(
            $proposal,
            new Marking([ProposalStatus::Declined->value => 1]),
        );

        self::assertSame(
            ProposalStatus::Declined,
            $proposal->status,
        );
    }

    public function testARevisionsStatusIsItsMarking(): void
    {
        $revision = new CompanyRevision();
        $revision->setStatus(RevisionStatus::InReview);

        $marking = new RevisionStatusMarkingStore()->getMarking($revision);

        self::assertSame(
            [RevisionStatus::InReview->value => 1],
            $marking->getPlaces(),
        );
    }

    public function testARevisionsMarkingIsItsStatus(): void
    {
        $revision = new CompanyRevision();

        new RevisionStatusMarkingStore()->setMarking(
            $revision,
            new Marking([RevisionStatus::Approved->value => 1]),
        );

        self::assertSame(
            RevisionStatus::Approved,
            $revision->getStatus(),
        );
    }
}
