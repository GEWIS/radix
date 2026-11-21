<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Checker;

use App\Service\Checker\Annulment;
use App\Service\Checker\Checker;
use App\Service\Checker\Document;
use App\Service\Checker\Installation;
use App\Service\Checker\Key;
use App\Service\Checker\Meeting;
use App\Service\Checker\Organ;
use App\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

use function str_contains;

/**
 * The checker's whole job is the report: it walks the ledger meeting by meeting and sends what it found to the
 * secretary. What it finds is covered check by check in {@see CheckerTest}; this is the walk and the mail around it.
 */
final class CheckerReportTest extends DatabaseTestCase
{
    public function testTheWholeLedgerIsCheckedAndReportedToTheSecretary(): void
    {
        $mailer = $this->mailer();
        $mailer->expects(self::once())->method('send');

        $this->checker($mailer)->check();
    }

    public function testTheDischargeCheckReportsTheLastMeetingOnly(): void
    {
        $mailer = $this->mailer();
        $mailer->expects(self::once())->method('send');

        $this->checker($mailer)->checkDischarges();
    }

    /**
     * The report names its own subject and carries the per-meeting findings, which the template renders.
     */
    public function testTheReportIsAddressedAndTemplated(): void
    {
        $sent = null;
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (TemplatedEmail $email) use (&$sent): void {
            $sent = $email;
        });

        $this->checker($mailer)->checkDischarges();

        self::assertInstanceOf(
            TemplatedEmail::class,
            $sent,
        );
        self::assertSame(
            'Database Checker Report',
            $sent->getSubject(),
        );
        self::assertSame(
            'database/checker/report.txt.twig',
            $sent->getTextTemplate(),
        );
        self::assertArrayHasKey(
            'reports',
            $sent->getContext(),
        );
        self::assertTrue(str_contains((string) $sent->getTo()[0]->getAddress(), '@'));
    }

    private function mailer(): MailerInterface&MockObject
    {
        return $this->createMock(MailerInterface::class);
    }

    private function checker(MailerInterface $mailer): Checker
    {
        return new Checker(
            self::getContainer()->get(Annulment::class),
            self::getContainer()->get(Document::class),
            self::getContainer()->get(Installation::class),
            self::getContainer()->get(Key::class),
            self::getContainer()->get(Meeting::class),
            self::getContainer()->get(Organ::class),
            $mailer,
            'sender@gewis.nl',
            'GEWIS',
            'secretary@gewis.nl',
            'Secretary',
        );
    }
}
