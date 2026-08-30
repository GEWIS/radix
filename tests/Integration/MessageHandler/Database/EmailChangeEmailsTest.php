<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler\Database;

use App\Entity\Database\Member;
use App\Message\Database\EmailChangeConfirmationEmail;
use App\Message\Database\EmailChangedNoticeEmail;
use App\MessageHandler\Database\EmailChangeConfirmationEmailHandler;
use App\MessageHandler\Database\EmailChangedNoticeEmailHandler;
use App\Service\Database\ActionLinkService;
use App\Service\Database\Member as MemberService;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

use function preg_match;

#[CoversClass(EmailChangeConfirmationEmailHandler::class)]
#[CoversClass(EmailChangedNoticeEmailHandler::class)]
final class EmailChangeEmailsTest extends DatabaseTestCase
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

    public function testTheConfirmationGoesToTheNewAddressAndCarriesAWorkingLink(): void
    {
        $member = $this->member();
        $link = self::getContainer()->get(MemberService::class)->requestEmailChange(
            $member,
            'somewhere-else@example.org',
        );

        self::getContainer()->get(EmailChangeConfirmationEmailHandler::class)->__invoke(
            new EmailChangeConfirmationEmail(
                $member->getLidnr(),
                'somewhere-else@example.org',
                (string) $link->getPlainToken(),
            ),
        );

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(
            Email::class,
            $email,
        );

        self::assertSame(
            'somewhere-else@example.org',
            $email->getTo()[0]->getAddress(),
            'A confirmation is worth something only when it goes to the address being claimed.',
        );

        $body = (string) $email->getHtmlBody();
        self::assertStringContainsString(
            'somewhere-else@example.org',
            $body,
        );

        // The link in the message is the credential. Following it has to lead back to this change and no other.
        self::assertSame(
            1,
            preg_match(
                '{/en/user/email-change/([0-9a-f]{32}\.[0-9a-f]{96})}',
                $body,
                $matches,
            ),
            'The message carries a link to the confirmation page.',
        );
        self::assertSame(
            $link->getId(),
            self::getContainer()->get(ActionLinkService::class)->resolveEmailChange($matches[1])?->getId(),
        );
    }

    public function testTheNoticeGoesToTheAddressThatWasReplaced(): void
    {
        $member = $this->member();

        self::getContainer()->get(EmailChangedNoticeEmailHandler::class)->__invoke(
            new EmailChangedNoticeEmail(
                $member->getLidnr(),
                'was@example.org',
                'is-now@example.org',
            ),
        );

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(
            Email::class,
            $email,
        );

        self::assertSame(
            'was@example.org',
            $email->getTo()[0]->getAddress(),
        );

        $body = (string) $email->getHtmlBody();
        self::assertStringContainsString(
            'was@example.org',
            $body,
        );
        self::assertStringContainsString(
            'is-now@example.org',
            $body,
        );
    }

    private function member(): Member
    {
        $member = $this->ledger->getRepository(Member::class)->createQueryBuilder('m')
            ->where('m.email IS NOT NULL')
            ->andWhere('m.deleted = false')
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
