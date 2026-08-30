<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler\Database;

use App\Entity\Database\Member;
use App\Message\Database\GraduateRemovalRequested;
use App\MessageHandler\Database\GraduateRemovalRequestedHandler;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

#[CoversClass(GraduateRemovalRequestedHandler::class)]
final class GraduateRemovalRequestedHandlerTest extends DatabaseTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $ledger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get('doctrine')->getManager('default');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->ledger = $ledger;
    }

    public function testReachesTheSecretaryAndSaysWhoAsked(): void
    {
        $member = $this->member();

        self::getContainer()->get(GraduateRemovalRequestedHandler::class)->__invoke(
            new GraduateRemovalRequested($member->getLidnr()),
        );

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(
            Email::class,
            $email,
        );

        $body = (string) $email->getHtmlBody();

        self::assertStringContainsString(
            $member->getFullName(),
            $body,
        );
        self::assertStringContainsString(
            (string) $member->getLidnr(),
            $body,
        );
        // The record itself, so the secretary can act on it without going looking.
        self::assertStringContainsString(
            '/admin/members/' . $member->getLidnr(),
            $body,
        );
    }

    public function testSaysNothingAboutAMemberWhoIsAlreadyGone(): void
    {
        self::getContainer()->get(GraduateRemovalRequestedHandler::class)->__invoke(
            new GraduateRemovalRequested(999999),
        );

        self::assertEmailCount(0);
    }

    private function member(): Member
    {
        $member = $this->ledger->getRepository(Member::class)->createQueryBuilder('m')
            ->where('m.deleted = false')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }
}
