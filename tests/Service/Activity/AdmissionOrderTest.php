<?php

declare(strict_types=1);

namespace App\Tests\Service\Activity;

use App\Entity\Activity\Enums\CohortTier;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupRole;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\AssociationYear;
use App\Entity\Application\PriorityTierInterface;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\ProgramType;
use App\Entity\Database\Enums\Studies;
use App\Entity\Decision\Member;
use App\Service\Activity\AdmissionOrder;
use App\Tests\Support\BuildsSignupRoles;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function spl_object_id;

#[CoversClass(AdmissionOrder::class)]
final class AdmissionOrderTest extends TestCase
{
    use BuildsSignupRoles;

    private AdmissionOrder $order;

    /** @var array<int, string> */
    private array $names = [];

    protected function setUp(): void
    {
        $this->order = new AdmissionOrder();
    }

    public function testAListWithoutModifiersKeepsTheOrderItWasGiven(): void
    {
        $list = $this->list();
        $pool = [
            $this->external('a'),
            $this->member('b'),
        ];

        self::assertSame(
            [
                'a',
                'b',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testMembersAreServedBeforeGraduatesAndNonMembers(): void
    {
        $list = $this->list();
        $list->setMembershipTierOrder(self::ranks(MembershipTier::defaultOrder()));
        $list->setMembershipPriorityMode(MembershipPriorityMode::Ordering);

        $pool = [
            $this->external('external'),
            $this->member(
                'graduate',
                type: MembershipTypes::Graduate,
            ),
            $this->member('member'),
        ];

        self::assertSame(
            [
                'member',
                'graduate',
                'external',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testTiersOnTheSameRankAreAdmittedTogether(): void
    {
        $list = $this->list();
        $list->setMembershipTierOrder([
            [
                MembershipTier::Ordinary,
                MembershipTier::Graduate,
            ],
            [MembershipTier::NonMember],
        ]);
        $list->setMembershipPriorityMode(MembershipPriorityMode::Ordering);

        $pool = [
            $this->external('external'),
            $this->member(
                'graduate',
                type: MembershipTypes::Graduate,
            ),
            $this->member('member'),
        ];

        // Everybody with an account comes before the outside world, and between the two of them the order the
        // allocation method produced stands.
        self::assertSame(
            [
                'graduate',
                'member',
                'external',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testTheOrderWithinATierIsTheOneTheMethodProduced(): void
    {
        $list = $this->list();
        $list->setMembershipTierOrder(self::ranks(MembershipTier::defaultOrder()));
        $list->setMembershipPriorityMode(MembershipPriorityMode::Ordering);

        $pool = [
            $this->external('external-first'),
            $this->member('member-first'),
            $this->external('external-second'),
            $this->member('member-second'),
        ];

        self::assertSame(
            [
                'member-first',
                'member-second',
                'external-first',
                'external-second',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testAnOrganiserCanTurnTheMembershipOrderAround(): void
    {
        $list = $this->list();
        $list->setMembershipTierOrder([
            [MembershipTier::NonMember],
            [MembershipTier::Ordinary],
            [MembershipTier::Graduate],
        ]);
        $list->setMembershipPriorityMode(MembershipPriorityMode::Ordering);

        $pool = [
            $this->member('member'),
            $this->external('external'),
        ];

        self::assertSame(
            [
                'external',
                'member',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testHeldPlacesMoveOnlyTheirOwnTierForwardAndLeaveTheRestAlone(): void
    {
        $list = $this->list();
        $list->setMembershipTierOrder(self::ranks(MembershipTier::defaultOrder()));
        $list->setMembershipPriorityMode(MembershipPriorityMode::ReservedPlaces);
        $list->setHeldMembershipPlaces([MembershipTier::Ordinary->value => 1]);

        $pool = [
            $this->external('external-first'),
            $this->external('external-second'),
            $this->member('member-first'),
            $this->member('member-second'),
        ];

        self::assertSame(
            [
                'member-first',
                'external-first',
                'external-second',
                'member-second',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testMasterStudentsCanBeAdmittedBeforeBachelorStudents(): void
    {
        $list = $this->list();
        $list->setOnlyGEWIS(true);
        $list->setProgramTypeOrder([
            [ProgramType::Master],
            [ProgramType::Bachelor],
            [ProgramType::Doctorate],
            [ProgramType::Other],
        ]);

        $pool = [
            $this->member(
                'bachelor',
                study: Studies::BCS,
            ),
            $this->member(
                'premaster',
                study: Studies::PMES,
            ),
            $this->member(
                'master',
                study: Studies::MCSE,
            ),
        ];

        self::assertSame(
            [
                'premaster',
                'master',
                'bachelor',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testFreshmenCanBeAdmittedFirst(): void
    {
        $list = $this->list();
        $list->setOnlyGEWIS(true);
        $list->setCohortTierOrder(self::ranks(CohortTier::defaultOrder()));

        $current = AssociationYear::fromDate(new DateTime())->getYear();
        $pool = [
            $this->member(
                'no-cohort',
                generation: 0,
            ),
            $this->member(
                'senior',
                generation: $current - 4,
            ),
            $this->member(
                'freshman',
                generation: $current,
            ),
            $this->member(
                'second-year',
                generation: $current - 1,
            ),
        ];

        self::assertSame(
            [
                'freshman',
                'second-year',
                'senior',
                'no-cohort',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testWhatSomebodyIsToTheAssociationOutranksTheirStudy(): void
    {
        $list = $this->list();
        $list->setMembershipTierOrder(self::ranks(MembershipTier::defaultOrder()));
        $list->setMembershipPriorityMode(MembershipPriorityMode::Ordering);
        $list->setProgramTypeOrder([
            [ProgramType::Master],
            [ProgramType::Bachelor],
            [ProgramType::Doctorate],
            [ProgramType::Other],
        ]);

        $pool = [
            $this->member(
                'graduate-master',
                type: MembershipTypes::Graduate,
                study: Studies::MCSE,
            ),
            $this->member(
                'member-bachelor',
                study: Studies::BCS,
            ),
        ];

        self::assertSame(
            [
                'member-bachelor',
                'graduate-master',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testAShortfallInARoleIsMadeUpFromTheWaitingList(): void
    {
        $list = $this->list(capacity: 3);
        $driver = $this->role(
            $list,
            'Driver',
            1,
        );

        $pool = [
            $this->member('a'),
            $this->member('b'),
            $this->member('c'),
            $this->member('d'),
            $this->member(
                'e',
                role: $driver,
            ),
        ];

        self::assertSame(
            [
                'a',
                'b',
                'e',
                'c',
                'd',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testARoleThatIsAlreadyFilledChangesNothing(): void
    {
        $list = $this->list(capacity: 3);
        $driver = $this->role(
            $list,
            'Driver',
            1,
        );

        $pool = [
            $this->member(
                'a',
                role: $driver,
            ),
            $this->member('b'),
            $this->member('c'),
            $this->member(
                'd',
                role: $driver,
            ),
        ];

        self::assertSame(
            [
                'a',
                'b',
                'c',
                'd',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testSomebodyHoldingARoleIsNeverDisplacedToMakeRoomForAnother(): void
    {
        $list = $this->list(capacity: 3);
        $driver = $this->role(
            $list,
            'Driver',
            1,
        );
        $skipper = $this->role(
            $list,
            'Skipper',
            1,
        );

        $pool = [
            $this->member(
                'a',
                role: $driver,
            ),
            $this->member('b'),
            $this->member('c'),
            $this->member(
                'd',
                role: $skipper,
            ),
        ];

        self::assertSame(
            [
                'a',
                'b',
                'd',
                'c',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    public function testNothingIsMadeUpWhenTheWaitingListHoldsNobodyWithTheRole(): void
    {
        $list = $this->list(capacity: 2);
        $this->role(
            $list,
            'Driver',
            1,
        );

        $pool = [
            $this->member('a'),
            $this->member('b'),
            $this->member('c'),
        ];

        self::assertSame(
            [
                'a',
                'b',
                'c',
            ],
            $this->arrange(
                $list,
                $pool,
            ),
        );
    }

    /**
     * @param list<Signup> $pool
     *
     * @return list<string>
     */
    private function arrange(
        SignupList $list,
        array $pool,
    ): array {
        return array_map(
            fn (Signup $signup): string => $this->names[spl_object_id($signup)],
            $this->order->arrange(
                $list,
                $pool,
            ),
        );
    }

    /**
     * @template T of PriorityTierInterface
     *
     * @param list<T> $tiers
     *
     * @return list<list<T>>
     */
    private static function ranks(array $tiers): array
    {
        return array_map(
            static fn (PriorityTierInterface $tier): array => [$tier],
            $tiers,
        );
    }

    private function list(int $capacity = 10): SignupList
    {
        $list = new SignupList();
        $list->setLimitedCapacity(true);
        $list->setCapacity($capacity);

        return $list;
    }

    private function member(
        string $name,
        MembershipTypes $type = MembershipTypes::Ordinary,
        Studies $study = Studies::BCS,
        int $generation = 2000,
        ?SignupRole $role = null,
    ): UserSignup {
        $member = new Member();
        $member->setType($type);
        $member->setStudy($study);
        $member->setGeneration($generation);

        $signup = new UserSignup();
        $signup->setUser($member);
        $signup->setRole($role);

        return $this->named(
            $signup,
            $name,
        );
    }

    private function external(string $name): ExternalSignup
    {
        return $this->named(
            new ExternalSignup(),
            $name,
        );
    }

    /**
     * @template T of Signup
     *
     * @param T $signup
     *
     * @return T
     */
    private function named(
        Signup $signup,
        string $name,
    ): Signup {
        $this->names[spl_object_id($signup)] = $name;

        return $signup;
    }
}
