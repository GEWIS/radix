<?php

declare(strict_types=1);

namespace App\DataFixtures\Database;

use App\DataFixtures\Member\MemberFixture;
use App\Entity\Database\Decision;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member as MemberModel;
use App\Entity\Database\SubDecision\Financial\Budget;
use App\Entity\Database\SubDecision\Financial\Statement;
use App\Entity\Database\SubDecision\Key\Granting;
use App\Entity\Database\SubDecision\Key\Withdrawal;
use App\Entity\Database\SubDecision\Minutes;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Override;

/**
 * The decisions a board takes that are not about bodies: minutes, money and key codes.
 *
 * Without these the meeting pages and the decision export only ever show installations, and the kinds of decision
 * that are hardest to get right are the ones nobody has an example of.
 */
class AdministrationFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public const string REF_MEETING_BV4 = 'meeting-bv-4';
    public const string REF_KEY_GRANTING = 'key-granting';

    #[Override]
    public function load(ObjectManager $manager): void
    {
        $meeting = new Meeting();
        $meeting->type = MeetingTypes::BV;
        $meeting->setNumber(4);
        // Later than the board meeting DecisionFixture holds, because meetings of a type are numbered in the
        // order they are held.
        $meeting->date = new DateTime()->modify('-3 days');
        $manager->persist($meeting);
        $this->addReference(
            self::REF_MEETING_BV4,
            $meeting,
        );

        $treasurer = $this->getReference(
            MemberFixture::REF_MEMBER_ATTN_ORDINARY_ACTIVE,
            MemberModel::class,
        );
        $keyholder = $this->getReference(
            MemberFixture::REF_MEMBER_STUDENT,
            MemberModel::class,
        );
        $formerKeyholder = $this->getReference(
            MemberFixture::REF_MEMBER_EXTERNAL,
            MemberModel::class,
        );

        // Approving the minutes of the first board meeting. The member is the secretary who wrote them, and the
        // content names them, so it is not optional.
        $decision = $this->decision(
            $manager,
            $meeting,
            1,
        );
        $minutes = new Minutes();
        $minutes->setMember($treasurer);
        $minutes->setTarget($this->getReference(MeetingFixture::REF_MEETING_BV1, Meeting::class));
        $minutes->approval = true;
        $minutes->changes = false;
        $minutes->sequence = 1;
        $minutes->setDecision($decision);
        $decision->addSubdecision($minutes);
        $manager->persist($minutes);

        // A budget, approved as submitted.
        $decision = $this->decision(
            $manager,
            $meeting,
            2,
        );
        $budget = new Budget();
        $budget->name = 'Begroting Attention Test Committee';
        $budget->version = '1.0';
        $budget->date = new DateTime()->modify('-2 months');
        $budget->approval = true;
        $budget->changes = false;
        $budget->setMember($treasurer);
        $budget->sequence = 1;
        $budget->setDecision($decision);
        $decision->addSubdecision($budget);
        $manager->persist($budget);

        // A statement, approved with changes — the other half of the pair, and the case where `changes` is true.
        $decision = $this->decision(
            $manager,
            $meeting,
            3,
        );
        $statement = new Statement();
        $statement->name = 'Afrekening Attention Test Committee';
        $statement->version = '1.1';
        $statement->date = new DateTime()->modify('-2 months');
        $statement->approval = true;
        $statement->changes = true;
        $statement->setMember($treasurer);
        $statement->sequence = 1;
        $statement->setDecision($decision);
        $decision->addSubdecision($statement);
        $manager->persist($statement);

        // A key code that is still held.
        $decision = $this->decision(
            $manager,
            $meeting,
            4,
        );
        $granting = new Granting();
        $granting->setMember($keyholder);
        $granting->until = new DateTime()->modify('+6 months');
        $granting->sequence = 1;
        $granting->setDecision($decision);
        $decision->addSubdecision($granting);
        $manager->persist($granting);
        $this->addReference(
            self::REF_KEY_GRANTING,
            $granting,
        );

        // And one that was granted and then withdrawn, so the withdrawal has something to point at.
        $decision = $this->decision(
            $manager,
            $meeting,
            5,
        );
        $earlier = new Granting();
        $earlier->setMember($formerKeyholder);
        $earlier->until = new DateTime()->modify('+1 year');
        $earlier->sequence = 1;
        $earlier->setDecision($decision);
        $decision->addSubdecision($earlier);
        $manager->persist($earlier);

        $withdrawal = new Withdrawal();
        $withdrawal->granting = $earlier;
        $withdrawal->withdrawnOn = clone $meeting->date;
        $withdrawal->sequence = 2;
        $withdrawal->setDecision($decision);
        $decision->addSubdecision($withdrawal);
        $manager->persist($withdrawal);

        $manager->flush();
    }

    private function decision(
        ObjectManager $manager,
        Meeting $meeting,
        int $point,
    ): Decision {
        $decision = new Decision();
        $decision->setMeeting($meeting);
        $decision->point = $point;
        $decision->number = 1;
        $manager->persist($decision);

        return $decision;
    }

    /**
     * @return array<class-string<FixtureInterface>>
     */
    #[Override]
    public function getDependencies(): array
    {
        return [
            MemberFixture::class,
            MeetingFixture::class,
            DecisionFixture::class,
        ];
    }

    /**
     * @return string[]
     */
    #[Override]
    public static function getGroups(): array
    {
        return ['ledger'];
    }
}
