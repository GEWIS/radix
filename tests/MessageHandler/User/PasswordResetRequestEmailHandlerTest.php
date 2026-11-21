<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\User;

use App\Entity\Career\Company;
use App\Entity\Decision\Member;
use App\Entity\User\CompanyUser;
use App\Entity\User\Enums\SecurityEventType;
use App\Entity\User\Enums\UserTypes;
use App\Entity\User\PasswordReset;
use App\Entity\User\SecurityLog;
use App\Message\User\PasswordResetRequestEmail;
use App\MessageHandler\User\PasswordResetRequestEmailHandler;
use App\Monolog\Processor\RequestIdProcessor;
use App\Repository\Decision\MemberRepository;
use App\Repository\User\CompanyUserRepository;
use App\Repository\User\PasswordResetRepository;
use App\Repository\User\SecurityLogRepository;
use App\Security\User\UserAgentParser;
use App\Service\User\SecurityEventLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function preg_match;

/**
 * A reset mail is sent only when it creates a usable token. An address or number with no account, and an account
 * with a link valid for another quarter of an hour, both succeed silently. The handler records the request, because
 * it is the first point where the account is known.
 */
final class PasswordResetRequestEmailHandlerTest extends TestCase
{
    private const string EMAIL = 'ada@example.com';

    public function testAMemberGetsACompanylessResetLinkAndTheRequestIsRecorded(): void
    {
        $sent = [];
        $persisted = [];
        $appended = [];

        $member = $this->member();
        $handler = $this->handler(
            $sent,
            $persisted,
            $appended,
            $member,
        );

        $handler($this->message(UserTypes::User));

        self::assertCount(
            1,
            $sent,
        );
        $email = $sent[0];
        self::assertSame(
            self::EMAIL,
            $email->getTo()[0]->getAddress(),
        );
        self::assertSame(
            'Your password reset request',
            $email->getSubject(),
        );
        self::assertSame(
            'emails/user/password-reset.html.twig',
            $email->getHtmlTemplate(),
        );
        self::assertSame(
            'Ada Lovelace',
            $email->getContext()['fullName'],
        );
        self::assertSame(
            1,
            preg_match(
                '#\Ahttps://gewis\.nl/user_password_reset_claim\?token=[0-9a-f]{32}\.[0-9a-f]{64}\z#',
                $email->getContext()['resetUrl'],
            ),
        );

        self::assertCount(
            1,
            $persisted,
        );
        $reset = $persisted[0];
        self::assertSame(
            UserTypes::User,
            $reset->userType,
        );
        self::assertSame(
            $member,
            $reset->member,
        );
        // Fifteen minutes, with room for the time this test itself takes.
        self::assertGreaterThan(
            new DateTimeImmutable('now +14 minutes'),
            $reset->expiresAt,
        );
        self::assertLessThan(
            new DateTimeImmutable('now +16 minutes'),
            $reset->expiresAt,
        );

        self::assertCount(
            1,
            $appended,
        );
        self::assertSame(
            SecurityEventType::PasswordResetRequested,
            $appended[0]->event,
        );
        self::assertSame(
            '8025',
            $appended[0]->userIdentifier,
        );
        self::assertSame(
            'main',
            $appended[0]->firewallName,
        );
    }

    public function testACompanyRepresentativeGetsTheCompanyResetLink(): void
    {
        $sent = [];
        $persisted = [];
        $appended = [];

        $companyUser = new CompanyUser();
        $companyUser->email = 'rep@example.com';
        $companyUser->name = 'Grace Hopper';
        $companyUser->company = new Company();

        $handler = $this->handler(
            $sent,
            $persisted,
            $appended,
            null,
            $companyUser,
        );

        $handler(new PasswordResetRequestEmail(
            UserTypes::CompanyUser,
            'rep@example.com',
        ));

        self::assertCount(
            1,
            $sent,
        );
        self::assertSame(
            'rep@example.com',
            $sent[0]->getTo()[0]->getAddress(),
        );
        self::assertSame(
            'Grace Hopper',
            $sent[0]->getContext()['fullName'],
        );
        self::assertSame(
            1,
            preg_match(
                '#\Ahttps://gewis\.nl/company_user_password_reset_claim\?token=[0-9a-f]{32}\.[0-9a-f]{64}\z#',
                $sent[0]->getContext()['resetUrl'],
            ),
        );

        self::assertSame(
            UserTypes::CompanyUser,
            $persisted[0]->userType,
        );
        self::assertSame(
            $companyUser,
            $persisted[0]->companyUser,
        );
        self::assertSame(
            'company',
            $appended[0]->firewallName,
        );
        self::assertSame(
            'rep@example.com',
            $appended[0]->userIdentifier,
        );
    }

    public function testANonMemberAddressIsSilentlyIgnored(): void
    {
        $sent = [];
        $persisted = [];
        $appended = [];

        $handler = $this->handler(
            $sent,
            $persisted,
            $appended,
            null,
        );

        $handler($this->message(UserTypes::User));

        self::assertSame(
            [],
            $sent,
        );
        self::assertSame(
            [],
            $persisted,
        );
        self::assertSame(
            [],
            $appended,
        );
    }

    public function testTheSameRequestWithinTheGracePeriodDoesNotSendASecondMail(): void
    {
        $sent = [];
        $persisted = [];
        $appended = [];

        $existing = new PasswordReset(
            new DateTimeImmutable('now +5 minutes'),
            'selector',
            'hash',
            $this->member(),
        );

        $handler = $this->handler(
            $sent,
            $persisted,
            $appended,
            $this->member(),
            null,
            $existing,
        );

        $handler($this->message(UserTypes::User));

        self::assertSame(
            [],
            $sent,
        );
        self::assertSame(
            [],
            $persisted,
        );
        self::assertSame(
            [],
            $appended,
        );
    }

    public function testATokenThatIsAboutToExpireIsReplacedRatherThanReliedOn(): void
    {
        $sent = [];
        $persisted = [];
        $appended = [];

        // Within the one-minute grace window a valid link would expire before use, so a fresh token is issued and the
        // old one stops working.
        $existing = new PasswordReset(
            new DateTimeImmutable('now +30 seconds'),
            'selector',
            'hash',
            $this->member(),
        );

        $handler = $this->handler(
            $sent,
            $persisted,
            $appended,
            $this->member(),
            null,
            $existing,
        );

        $handler($this->message(UserTypes::User));

        self::assertCount(
            1,
            $sent,
        );
        self::assertCount(
            1,
            $persisted,
        );
        self::assertNotSame(
            $existing,
            $persisted[0],
        );
        self::assertCount(
            1,
            $appended,
        );
    }

    public function testAnExpiredTokenIsReplaced(): void
    {
        $sent = [];
        $persisted = [];
        $appended = [];

        $existing = new PasswordReset(
            new DateTimeImmutable('now -1 minute'),
            'selector',
            'hash',
            $this->member(),
        );

        $handler = $this->handler(
            $sent,
            $persisted,
            $appended,
            $this->member(),
            null,
            $existing,
        );

        $handler($this->message(UserTypes::User));

        self::assertCount(
            1,
            $sent,
        );
        self::assertCount(
            1,
            $persisted,
        );
    }

    /**
     * @param list<TemplatedEmail> $sent
     * @param list<PasswordReset>  $persisted
     * @param list<SecurityLog>    $appended
     */
    private function handler(
        array &$sent,
        array &$persisted,
        array &$appended,
        ?Member $member = null,
        ?CompanyUser $companyUser = null,
        ?PasswordReset $existing = null,
    ): PasswordResetRequestEmailHandler {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$sent): void {
                $sent[] = $email;
            },
        );

        $members = self::createStub(MemberRepository::class);
        $members->method('findForReset')->willReturn($member);

        $companyUsers = self::createStub(CompanyUserRepository::class);
        $companyUsers->method('loadUserByIdentifier')->willReturn($companyUser);

        $resets = self::createStub(PasswordResetRepository::class);
        $resets->method('findForMember')->willReturn($existing);
        $resets->method('findForCompanyUser')->willReturn($existing);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (PasswordReset $reset) use (&$persisted): void {
                $persisted[] = $reset;
            },
        );

        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static function (string $route, array $parameters): string {
                return 'https://gewis.nl/' . $route . '?token=' . $parameters['token'];
            },
        );

        $logs = self::createStub(SecurityLogRepository::class);
        $logs->method('append')->willReturnCallback(
            static function (SecurityLog $log) use (&$appended): void {
                $appended[] = $log;
            },
        );

        return new PasswordResetRequestEmailHandler(
            $mailer,
            $members,
            $companyUsers,
            $resets,
            $entityManager,
            $urlGenerator,
            new SecurityEventLogger(
                $logs,
                new RequestStack(),
                new UserAgentParser(),
                self::createStub(TokenStorageInterface::class),
                new RequestIdProcessor(),
                new MockClock(),
                new NullLogger(),
            ),
        );
    }

    private function message(UserTypes $type): PasswordResetRequestEmail
    {
        return new PasswordResetRequestEmail(
            $type,
            self::EMAIL,
            UserTypes::User === $type ? 8025 : null,
        );
    }

    private function member(): Member
    {
        $member = new Member();
        $member->lidnr = 8025;
        $member->email = self::EMAIL;
        $member->firstName = 'Ada';
        $member->middleName = '';
        $member->lastName = 'Lovelace';

        return $member;
    }
}
