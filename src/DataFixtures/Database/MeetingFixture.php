<?php

declare(strict_types=1);

namespace App\DataFixtures\Database;

use App\DataFixtures\Member\MemberFixture;
use App\Entity\Database\Decision;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member as MemberModel;
use App\Entity\Database\SubDecision\Board\Installation as BoardInstallation;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Override;

/**
 * The first board meeting, which installs a chair. Everything else is minuted from {@see DecisionFixture}.
 */
class MeetingFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public const string REF_MEETING_BV1 = 'meeting-bv-1';
    public const string REF_SUBDEC_BOARDINSTALL = 'subdecision-board-install';

    #[Override]
    public function load(ObjectManager $manager): void
    {
        $meeting = new Meeting();
        $meeting->date = new DateTimeImmutable('2000-01-01');
        $meeting->setNumber(1);
        $meeting->type = MeetingTypes::BV;
        $manager->persist($meeting);
        $this->addReference(
            self::REF_MEETING_BV1,
            $meeting,
        );

        $decision = new Decision();
        $decision->setMeeting($meeting);
        $decision->point = 1;
        $decision->number = 1;

        $installation = new BoardInstallation();
        $installation->date = new DateTimeImmutable('2000-01-01');
        $installation->function = BoardFunctions::Chair;
        $installation->setMember($this->getReference(MemberFixture::REF_MEMBER_STUDENT, MemberModel::class));
        $installation->sequence = 1;
        $installation->setDecision($decision);
        $decision->addSubdecision($installation);

        $manager->persist($decision);
        $manager->persist($installation);
        $manager->flush();

        $this->addReference(
            self::REF_SUBDEC_BOARDINSTALL,
            $installation,
        );
    }

    /**
     * @return array<class-string<FixtureInterface>>
     */
    #[Override]
    public function getDependencies(): array
    {
        return [MemberFixture::class];
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
