<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Member\Suspension;
use App\Entity\Database\SubDecision\Member\Warning;
use App\Tests\Support\BuildsDecisions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Warning::class)]
#[CoversClass(Suspension::class)]
final class MemberActionsTest extends TestCase
{
    use BuildsDecisions;

    public function testAWarningNamesTheMemberItIsIssuedTo(): void
    {
        $warning = new Warning();
        $warning->sequence = 1;
        $warning->setMember($this->member('Test', 'Lid'));
        $warning->setDecision($this->decision());

        self::assertSame(
            'Het bestuur deelt een officiële waarschuwing uit aan Test Lid.',
            $warning->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
        self::assertSame(
            'Test Lid',
            $warning->getMember()->getFullName(),
        );
    }

    /**
     * Both ends are part of the suspension, so the wording is "from ... through ..." rather than "until".
     */
    public function testASuspensionNamesBothEndsOfThePeriod(): void
    {
        $suspension = new Suspension();
        $suspension->sequence = 1;
        $suspension->setMember($this->member('Test', 'Lid'));
        $suspension->since = new DateTimeImmutable('2026-02-01');
        $suspension->until = new DateTimeImmutable('2026-03-01');
        $suspension->setDecision($this->decision());

        self::assertSame(
            'Test Lid wordt geschorst van 1 februari 2026 tot en met 1 maart 2026.',
            $suspension->getTranslatedContent(
                $this->translator(),
                AppLanguages::Dutch,
            ),
        );
    }

    public function testASuspensionIsReadInTheLanguageAsked(): void
    {
        $suspension = new Suspension();
        $suspension->sequence = 1;
        $suspension->setMember($this->member('Test', 'Lid'));
        $suspension->since = new DateTimeImmutable('2026-02-01');
        $suspension->until = new DateTimeImmutable('2026-03-01');
        $suspension->setDecision($this->decision());

        self::assertSame(
            'Test Lid wordt geschorst van 1 February 2026 tot en met 1 March 2026.',
            $suspension->getTranslatedContent(
                $this->translator(),
                AppLanguages::English,
            ),
        );
    }

    private function member(
        string $firstName,
        string $lastName,
    ): Member {
        $member = new Member();
        $member->lidnr = 8100;
        $member->initials = 'T.';
        $member->firstName = $firstName;
        $member->middleName = '';
        $member->lastName = $lastName;
        $member->changedOn = new DateTimeImmutable('2026-01-01');

        return $member;
    }
}
