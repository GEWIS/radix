<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\User;

use App\Entity\Career\Company;
use App\Entity\User\CompanyUserInvite;
use App\Message\User\CompanyUserInviteEmail;
use App\MessageHandler\User\CompanyUserInviteEmailHandler;
use App\Repository\User\CompanyUserInviteRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The invitation link is the only way into the career portal, and only the hash of its token is stored, so the URL
 * uses the plaintext token. The mail is always English, because the invited person has no account and no locale.
 */
final class CompanyUserInviteEmailHandlerTest extends TestCase
{
    public function testTheInviteIsMailedWithItsTokenAndEnglishPortalLink(): void
    {
        $sent = [];
        $this->handler(
            $this->invite(),
            $sent,
        )
            ->__invoke(new CompanyUserInviteEmail(7, 'selector.verifier'));

        self::assertCount(
            1,
            $sent,
        );
        $email = $sent[0];
        self::assertSame(
            'rep@example.com',
            $email->getTo()[0]->getAddress(),
        );
        self::assertSame(
            'You have been invited to the GEWIS career portal',
            $email->getSubject(),
        );
        self::assertSame(
            'emails/career/company-user-invite.html.twig',
            $email->getHtmlTemplate(),
        );
        self::assertSame(
            'https://gewis.nl/company/user/invite/accept?token=selector.verifier&_locale=en',
            $email->getContext()['inviteUrl'],
        );
        self::assertSame(
            'Grace Hopper',
            $email->getContext()['fullName'],
        );
        self::assertSame(
            'ACME',
            $email->getContext()['companyName'],
        );
        self::assertEquals(
            new DateTimeImmutable('2026-12-01 12:00:00'),
            $email->getContext()['expiresAt'],
        );
    }

    public function testAnInvitationThatWasWithdrawnIsNotMailed(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');

        $invites = self::createStub(CompanyUserInviteRepository::class);
        $invites->method('find')->willReturn(null);

        $handler = new CompanyUserInviteEmailHandler(
            $mailer,
            $invites,
            $urlGenerator,
        );
        $handler(new CompanyUserInviteEmail(
            7,
            'selector.verifier',
        ));
    }

    /**
     * @param list<TemplatedEmail> $sent
     */
    private function handler(
        CompanyUserInvite $invite,
        array &$sent,
    ): CompanyUserInviteEmailHandler {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$sent): void {
                $sent[] = $email;
            },
        );

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'company_user_invite_accept',
                [
                    'token' => 'selector.verifier',
                    '_locale' => 'en',
                ],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://gewis.nl/company/user/invite/accept?token=selector.verifier&_locale=en');

        $invites = self::createStub(CompanyUserInviteRepository::class);
        $invites->method('find')->willReturn($invite);

        return new CompanyUserInviteEmailHandler(
            $mailer,
            $invites,
            $urlGenerator,
        );
    }

    private function invite(): CompanyUserInvite
    {
        $company = new Company();
        $company->name = 'ACME';
        $company->slugName = 'acme';

        return new CompanyUserInvite(
            $company,
            'rep@example.com',
            'Grace Hopper',
            null,
            'selector',
            'hashed-token',
            new DateTimeImmutable('2026-12-01 12:00:00'),
        );
    }
}
