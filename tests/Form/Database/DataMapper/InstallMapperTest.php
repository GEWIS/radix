<?php

declare(strict_types=1);

namespace App\Tests\Form\Database\DataMapper;

use App\Entity\Database\Decision;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\SubDecision\Discharge;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Entity\Database\SubDecision\Reappointment;
use App\Form\Database\DataMapper\InstallMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(InstallMapper::class)]
final class InstallMapperTest extends TestCase
{
    use MapsDecisions;

    /**
     * A membership change records who stays, who leaves and then who joins, in that order, by sequence number.
     */
    public function testReappointmentsComeBeforeDischargesAndInstallations(): void
    {
        $foundation = new Foundation();
        $foundation->abbr = 'TC';
        $foundation->name = 'Taartcommissie';

        $staying = $this->installation(1);
        $leaving = $this->installation(2);

        $decision = $this->mapDecision(
            new InstallMapper(),
            [
                'subdecision' => $foundation,
                'reappointments' => [
                    $staying,
                    'not an installation',
                ],
                'discharges' => [
                    $leaving,
                    null,
                ],
                'installations' => [
                    [
                        'member' => $this->member(8200),
                        'function' => InstallationFunctions::Chair,
                    ],
                    [
                        'member' => $this->member(8201),
                        'function' => InstallationFunctions::Member,
                    ],
                    ['member' => $this->member(8202)],
                    ['function' => InstallationFunctions::Treasurer],
                    'not an entry',
                ],
            ],
        );

        self::assertSame(
            [
                Reappointment::class => [1],
                Discharge::class => [2],
                Installation::class => [
                    3,
                    4,
                ],
            ],
            $this->sequences($decision),
        );

        $reappointment = $this->all(
            $decision,
            Reappointment::class,
        )[0];
        self::assertSame(
            $staying,
            $reappointment->installation,
        );

        $discharge = $this->all(
            $decision,
            Discharge::class,
        )[0];
        self::assertSame(
            $leaving,
            $discharge->installation,
        );

        $installations = $this->all(
            $decision,
            Installation::class,
        );
        self::assertSame(
            [
                8200,
                8201,
            ],
            array_map(
                static fn (Installation $installation): int => $installation->getMember()->lidnr,
                $installations,
            ),
        );
        self::assertSame(
            [
                InstallationFunctions::Chair,
                InstallationFunctions::Member,
            ],
            array_map(
                static fn (Installation $installation): InstallationFunctions => $installation->function,
                $installations,
            ),
        );

        foreach ($installations as $installation) {
            self::assertSame(
                $foundation,
                $installation->foundation,
            );
        }
    }

    /**
     * A membership change is always about one existing organ; without it there is nothing to change and the empty
     * decision is left to validation.
     */
    public function testNothingIsBuiltWithoutAnOrgan(): void
    {
        $decision = $this->mapDecision(
            new InstallMapper(),
            [
                'subdecision' => null,
                'reappointments' => [$this->installation(1)],
                'discharges' => [$this->installation(2)],
                'installations' => [
                    [
                        'member' => $this->member(8203),
                        'function' => InstallationFunctions::Chair,
                    ],
                ],
            ],
        );

        self::assertCount(
            0,
            $decision->getSubdecisions(),
        );
    }

    public function testAnEmptyChangeBuildsNoSubDecisions(): void
    {
        $decision = $this->mapDecision(
            new InstallMapper(),
            [
                'subdecision' => new Foundation(),
                'reappointments' => null,
                'discharges' => null,
                'installations' => null,
            ],
        );

        self::assertCount(
            0,
            $decision->getSubdecisions(),
        );
    }

    private function installation(int $sequence): Installation
    {
        $installation = new Installation();
        $installation->sequence = $sequence;
        $installation->function = InstallationFunctions::Chair;
        $installation->setMember($this->member(8300 + $sequence));

        return $installation;
    }

    /**
     * @return array<class-string, list<int>>
     */
    private function sequences(Decision $decision): array
    {
        $sequences = [
            Reappointment::class => [],
            Discharge::class => [],
            Installation::class => [],
        ];

        foreach ($this->subDecisions($decision) as $subDecision) {
            if (!isset($sequences[$subDecision::class])) {
                continue;
            }

            $sequences[$subDecision::class][] = $subDecision->sequence;
        }

        return $sequences;
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
