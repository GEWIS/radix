<?php

declare(strict_types=1);

namespace App\Tests\Util\Activity;

use App\Entity\Activity\Enums\CohortTier;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\AssociationYear;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\ProgramType;
use App\Entity\Database\Enums\Studies;
use App\Entity\Decision\Member;
use App\Util\Activity\SignupTiers;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The draw and the page that explains it afterwards must compute the same category for every sign-up, and an external
 * sign-up has no member behind it at all.
 */
final class SignupTiersTest extends TestCase
{
    public function testAnOrderIsShownWithRanksAndTiersTogether(): void
    {
        self::assertSame(
            'tier & tier > tier',
            SignupTiers::orderText(
                [
                    [
                        MembershipTier::Ordinary,
                        MembershipTier::External,
                    ],
                    [CohortTier::FirstYear],
                ],
                $this->translator(),
            ),
        );
    }

    public function testNoOrderIsShownAsNothing(): void
    {
        self::assertNull(SignupTiers::orderText(null, $this->translator()));
    }

    public function testEveryOrderAListAdmitsInIsShown(): void
    {
        $list = self::createStub(SignupList::class);
        $list->method('getMembershipTierOrder')->willReturn([[MembershipTier::Ordinary]]);
        $list->method('getProgramTypeOrder')->willReturn(null);
        $list->method('getCohortTierOrder')->willReturn([[CohortTier::Senior]]);

        self::assertSame(
            [
                'tier',
                'tier',
            ],
            SignupTiers::orderTexts(
                $list,
                $this->translator(),
            ),
        );
    }

    public function testAnExternalSignupIsNotAMember(): void
    {
        self::assertSame(
            MembershipTier::NonMember,
            SignupTiers::membership(self::createStub(ExternalSignup::class)),
        );
    }

    public function testAMembersSignupIsCategorisedByMembershipType(): void
    {
        self::assertSame(
            MembershipTier::Honorary,
            SignupTiers::membership($this->userSignup(MembershipTypes::Honorary)),
        );
    }

    public function testAnExternalSignupHasNoCohort(): void
    {
        self::assertSame(
            CohortTier::Unknown,
            SignupTiers::cohort(self::createStub(ExternalSignup::class)),
        );
    }

    public function testAMemberWithoutAGenerationHasNoCohort(): void
    {
        self::assertSame(
            CohortTier::Unknown,
            SignupTiers::cohort($this->userSignup(generation: 0)),
        );
    }

    public function testTheCohortFollowsTheAssociationYear(): void
    {
        $year = AssociationYear::fromDate(new DateTimeImmutable())->getYear();

        self::assertSame(
            CohortTier::FirstYear,
            SignupTiers::cohort($this->userSignup(generation: $year)),
        );
        self::assertSame(
            CohortTier::SecondYear,
            SignupTiers::cohort($this->userSignup(generation: $year - 1)),
        );
        self::assertSame(
            CohortTier::Senior,
            SignupTiers::cohort($this->userSignup(generation: $year - 2)),
        );
    }

    public function testAnExternalSignupHasNoProgram(): void
    {
        self::assertSame(
            ProgramType::Other,
            SignupTiers::program(self::createStub(ExternalSignup::class)),
        );
    }

    public function testAProgramComesFromTheMembersStudy(): void
    {
        self::assertSame(
            ProgramType::Bachelor,
            SignupTiers::program($this->userSignup(study: Studies::BCS)),
        );
    }

    private function userSignup(
        MembershipTypes $type = MembershipTypes::Ordinary,
        int $generation = 2026,
        Studies $study = Studies::BCS,
    ): UserSignup {
        $member = self::createStub(Member::class);
        $member->type = $type;
        $member->generation = $generation;
        $member->study = $study;

        $signup = self::createStub(UserSignup::class);
        $signup->user = $member;

        return $signup;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('tier');

        return $translator;
    }
}
