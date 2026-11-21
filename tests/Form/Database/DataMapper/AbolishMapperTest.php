<?php

declare(strict_types=1);

namespace App\Tests\Form\Database\DataMapper;

use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\SubDecision\Abrogation;
use App\Entity\Database\SubDecision\Discharge;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Form\Database\DataMapper\AbolishMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function array_map;

#[CoversClass(AbolishMapper::class)]
final class AbolishMapperTest extends TestCase
{
    use MapsDecisions;

    /**
     * Abolishing an organ discharges its members and then abrogates the foundation. The discharge runs in reverse
     * join order.
     */
    public function testEveryoneStillInstalledIsDischargedInReverseOrderThenTheOrganIsAbrogated(): void
    {
        $foundation = $this->foundation();

        $first = $this->installation(
            $foundation,
            8301,
        );
        $alreadyGone = $this->installation(
            $foundation,
            8302,
        );
        $third = $this->installation(
            $foundation,
            8303,
        );

        $discharge = new Discharge();
        $discharge->installation = $alreadyGone;
        new ReflectionProperty(
            Installation::class,
            'discharge',
        )->setValue(
            $alreadyGone,
            $discharge,
        );

        $decision = $this->mapDecision(
            new AbolishMapper(),
            ['subdecision' => $foundation],
        );

        self::assertSame(
            [
                [
                    Discharge::class,
                    1,
                    $third,
                ],
                [
                    Discharge::class,
                    2,
                    $first,
                ],
                [
                    Abrogation::class,
                    3,
                    null,
                ],
            ],
            array_map(
                static fn ($subDecision): array => [
                    $subDecision::class,
                    $subDecision->sequence,
                    $subDecision instanceof Discharge ? $subDecision->installation : null,
                ],
                $this->subDecisions($decision),
            ),
        );

        $abrogation = $this->subDecisions($decision)[2];
        self::assertInstanceOf(
            Abrogation::class,
            $abrogation,
        );
        self::assertSame(
            $foundation,
            $abrogation->foundation,
        );
    }

    /**
     * An organ that was never used still needs an abrogation, which takes sequence 1.
     */
    public function testAnEmptyOrganIsSimplyAbrogated(): void
    {
        $decision = $this->mapDecision(
            new AbolishMapper(),
            ['subdecision' => $this->foundation()],
        );

        self::assertCount(
            1,
            $decision->getSubdecisions(),
        );
        self::assertSame(
            1,
            $this->subDecisions($decision)[0]->sequence,
        );
        self::assertInstanceOf(
            Abrogation::class,
            $this->subDecisions($decision)[0],
        );
    }

    public function testNothingIsBuiltWithoutAFoundation(): void
    {
        $decision = $this->mapDecision(
            new AbolishMapper(),
            ['subdecision' => null],
        );

        self::assertCount(
            0,
            $decision->getSubdecisions(),
        );
    }

    private function foundation(): Foundation
    {
        $foundation = new Foundation();
        $foundation->abbr = 'TC';
        $foundation->name = 'Taartcommissie';

        return $foundation;
    }

    private function installation(
        Foundation $foundation,
        int $lidnr,
    ): Installation {
        $installation = new Installation();
        $installation->foundation = $foundation;
        $installation->function = InstallationFunctions::Chair;
        $installation->setMember($this->member($lidnr));
        $foundation->getReferences()->add($installation);

        return $installation;
    }
}
