<?php

declare(strict_types=1);

namespace App\Tests\Form\Database\DataMapper;

use App\Entity\Database\Decision;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Form\Database\DataMapper\FoundationMapper;
use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_map;
use function get_object_vars;
use function json_encode;

#[CoversClass(FoundationMapper::class)]
final class FoundationMapperTest extends TestCase
{
    use MapsDecisions;

    private const array FOUNDING = [
        'type' => OrganTypes::Committee,
        'name' => 'Taartcommissie',
        'abbr' => 'TC',
        'members' => [],
    ];

    public function testFoundsAnOrdinaryOrganWithTheNameAndAbbreviationGiven(): void
    {
        $decision = $this->mapDecision(
            new FoundationMapper(),
            self::FOUNDING,
        );

        $foundation = $this->only(
            $decision,
            Foundation::class,
        );
        self::assertSame(
            1,
            $foundation->sequence,
        );
        self::assertSame(
            OrganTypes::Committee,
            $foundation->organType,
        );
        self::assertSame(
            'Taartcommissie',
            $foundation->name,
        );
        self::assertSame(
            'TC',
            $foundation->abbr,
        );
        self::assertNull($foundation->getPurpose());
    }

    /**
     * A ballot committee exists for one meeting only, so it is named after that meeting instead of after the body the
     * secretary typed, and the abbreviation carries the meeting number too.
     */
    public function testABallotCommitteeIsNamedAfterItsMeeting(): void
    {
        $decision = $this->mapDecision(
            new FoundationMapper(),
            [
                'type' => OrganTypes::SC,
                'name' => 'de verkiezing van het bestuur',
                'abbr' => 'VK',
                'members' => [],
            ] + self::FOUNDING,
        );

        $foundation = $this->only(
            $decision,
            Foundation::class,
        );
        self::assertSame(
            'Stemcommissie voor de verkiezing van het bestuur van de 3e ALV',
            $foundation->name,
        );
        self::assertSame(
            'SC3-VK',
            $foundation->abbr,
        );
        self::assertSame(
            'de verkiezing van het bestuur',
            $foundation->getPurpose(),
        );
    }

    /**
     * A function in an organ does not make someone a member: the membership installation is written once, before the
     * functions, and only for a real function.
     */
    public function testEachFoundingMemberIsInstalledOncePlusTheirFunctions(): void
    {
        $chair = $this->member(
            8100,
            'Chair',
        );
        $member = $this->member(
            8101,
            'Member',
        );

        $decision = $this->mapDecision(
            new FoundationMapper(),
            [
                'members' => [
                    [
                        'member' => $chair,
                        'function' => InstallationFunctions::Chair,
                    ],
                    [
                        'member' => $chair,
                        'function' => InstallationFunctions::Treasurer,
                    ],
                    [
                        'member' => $member,
                        'function' => InstallationFunctions::Member,
                    ],
                    [
                        'member' => $this->member(8102),
                        'function' => InstallationFunctions::InactiveMember,
                    ],
                ],
            ] + self::FOUNDING,
        );

        $installations = $this->all(
            $decision,
            Installation::class,
        );

        self::assertSame(
            [
                [
                    8100,
                    InstallationFunctions::Member,
                ],
                [
                    8100,
                    InstallationFunctions::Chair,
                ],
                [
                    8100,
                    InstallationFunctions::Treasurer,
                ],
                [
                    8101,
                    InstallationFunctions::Member,
                ],
                [
                    8102,
                    InstallationFunctions::InactiveMember,
                ],
            ],
            array_map(
                static fn (Installation $installation): array => [
                    $installation->getMember()->lidnr,
                    $installation->function,
                ],
                $installations,
            ),
        );
        self::assertSame(
            [
                2,
                3,
                4,
                5,
                6,
            ],
            array_map(
                static fn (Installation $installation): int => $installation->sequence,
                $installations,
            ),
        );
    }

    /**
     * The entries in the collection come from a dynamic form, so an entry that is not a member plus a function is
     * skipped rather than forced. Validation reports the missing field itself.
     */
    public function testIncompleteMemberEntriesAreSkipped(): void
    {
        $decision = $this->mapDecision(
            new FoundationMapper(),
            [
                'members' => [
                    'not an entry',
                    ['member' => $this->member(8103)],
                    ['function' => InstallationFunctions::Chair],
                    [
                        'member' => $this->member(8104),
                        'function' => 'chair',
                    ],
                ],
            ] + self::FOUNDING,
        );

        self::assertCount(
            0,
            $this->all(
                $decision,
                Installation::class,
            ),
        );
    }

    public function testNothingIsFoundedUntilTheTypeAndNameAreComplete(): void
    {
        foreach (
            [
                ['name' => null],
                ['abbr' => null],
                ['type' => 'committee'],
            ] as $overrides
        ) {
            $decision = $this->mapDecision(
                new FoundationMapper(),
                $overrides + self::FOUNDING,
            );

            self::assertCount(
                0,
                $decision->getSubdecisions(),
                (string) json_encode($overrides),
            );
        }
    }

    /**
     * Without a meeting the decision has no identity and no place in the ledger, so the mapper leaves it alone; the
     * meeting field reports the omission.
     */
    public function testNoDecisionIsBuiltWithoutAMeeting(): void
    {
        $decision = new Decision();

        new FoundationMapper()->mapFormsToData(
            new ArrayIterator($this->children([
                'meeting' => null,
                'point' => 5,
                'decision' => 1,
            ] + self::FOUNDING)),
            $decision,
        );

        self::assertFalse(isset($decision->meeting));
        self::assertNull($decision->annulledBy);
    }

    public function testADecisionOfAnotherTypeIsNotMapped(): void
    {
        $viewData = new stdClass();

        new FoundationMapper()->mapFormsToData(
            new ArrayIterator($this->children(self::FOUNDING)),
            $viewData,
        );

        self::assertSame(
            [],
            get_object_vars($viewData),
        );
    }

    public function testAnExistingDecisionIsNeverReadBackIntoTheForm(): void
    {
        $forms = new ArrayIterator($this->children(self::FOUNDING));
        $before = [];
        foreach ($forms as $name => $form) {
            $before[$name] = $form->getData();
        }

        (new FoundationMapper())->mapDataToForms(
            new Decision(),
            $forms,
        );

        $after = [];
        foreach ($forms as $name => $form) {
            $after[$name] = $form->getData();
        }

        self::assertSame(
            $before,
            $after,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function only(
        Decision $decision,
        string $class,
    ): object {
        $matching = $this->all(
            $decision,
            $class,
        );
        self::assertCount(
            1,
            $matching,
        );

        return $matching[0];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function all(
        Decision $decision,
        string $class,
    ): array {
        $matching = [];
        foreach ($this->subDecisions($decision) as $subDecision) {
            if (!($subDecision instanceof $class)) {
                continue;
            }

            $matching[] = $subDecision;
        }

        return $matching;
    }
}
