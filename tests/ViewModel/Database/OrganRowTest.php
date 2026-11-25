<?php

declare(strict_types=1);

namespace App\Tests\ViewModel\Database;

use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Tests\Support\BuildsDecisions;
use App\ViewModel\Database\OrganRow;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function sprintf;

#[CoversClass(OrganRow::class)]
final class OrganRowTest extends TestCase
{
    use BuildsDecisions;

    /**
     * A body's page is identified by its foundation, not by the installation that happens to name it: the same page is
     * reached from everyone in the body, and it outlives each of them.
     */
    public function testTheRowPointsAtTheFoundationThatIdentifiesTheOrgan(): void
    {
        $foundation = new Foundation();
        $foundation->abbr = 'TC';
        $foundation->name = 'Taartcommissie';
        $foundation->sequence = 4;
        $foundation->setDecision($this->decision(
            $this->meeting(
                MeetingTypes::BV,
                18,
            ),
        ));

        $installation = new Installation();
        $installation->foundation = $foundation;
        $installation->sequence = 9;
        $installation->function = InstallationFunctions::Chair;
        $installation->setMember($this->member());

        $row = OrganRow::fromInstallation(
            $installation,
            $this->urlGenerator([
                'decision_organ_view',
                [
                    'type' => 'BV',
                    'number' => 18,
                    'point' => 5,
                    'decision' => 1,
                    'sequence' => 4,
                ],
            ]),
        );

        self::assertSame(
            'TC',
            $row->abbr,
        );
        self::assertSame(
            '/organ/BV/18/5/1/4',
            $row->url,
        );
    }

    /**
     * @param array{string, array<string, mixed>} $expected
     */
    private function urlGenerator(array $expected): UrlGeneratorInterface
    {
        $generator = self::createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(
            static function (string $name, array $parameters) use ($expected): string {
                self::assertSame(
                    $expected[0],
                    $name,
                );
                self::assertSame(
                    $expected[1],
                    $parameters,
                );

                return sprintf(
                    '/organ/%s/%d/%d/%d/%d',
                    $parameters['type'],
                    $parameters['number'],
                    $parameters['point'],
                    $parameters['decision'],
                    $parameters['sequence'],
                );
            },
        );

        return $generator;
    }

    private function member(): Member
    {
        $member = new Member();
        $member->lidnr = 8100;
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Lid';
        $member->changedOn = new DateTimeImmutable('2026-01-01');

        return $member;
    }
}
