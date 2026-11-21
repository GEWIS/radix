<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Database;

use App\Entity\Database\Member;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\ProspectiveMember;
use App\Message\Database\RegistrationUpdate;
use App\Message\Database\RegistrationUpdateEmail;
use App\MessageHandler\Database\RegistrationUpdateEmailHandler;
use App\Repository\Database\MemberRepository;
use App\Repository\Database\ProspectiveMemberRepository;
use App\Service\Application\Email;
use App\Service\Database\ActionLinkService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;

/**
 * The register mails the person it is about and copies the secretary. A record that was removed is mailed to nobody.
 */
final class RegistrationUpdateEmailHandlerTest extends TestCase
{
    private const string SECRETARY_ADDRESS = 'secretary@example.com';

    public function testAWelcomeMailGoesToTheMemberAndThenTheSecretary(): void
    {
        $sent = [];
        $handler = $this->handler(
            $sent,
            $this->member('ada@example.com'),
        );

        $handler(new RegistrationUpdateEmail(
            RegistrationUpdate::Welcome,
            8025,
        ));

        self::assertCount(
            2,
            $sent,
        );
        self::assertSame(
            [
                'ada@example.com',
                self::SECRETARY_ADDRESS,
            ],
            array_map(
                static fn (TemplatedEmail $email): string => $email->getTo()[0]->getAddress(),
                $sent,
            ),
        );
        self::assertSame(
            [
                'Your GEWIS membership has been confirmed',
                'Membership confirmed: Ada Lovelace',
            ],
            array_map(
                static function (TemplatedEmail $email): string {
                    $subject = $email->getSubject();
                    self::assertNotNull($subject);

                    return $subject;
                },
                $sent,
            ),
        );
        self::assertSame(
            'database/email/member-welcome.html.twig',
            $sent[0]->getHtmlTemplate(),
        );
        self::assertSame(
            'Ada',
            $sent[0]->getContext()['firstName'],
        );
        self::assertSame(
            8025,
            $sent[0]->getContext()['lidnr'],
        );
        self::assertNull($sent[0]->getContext()['restartUrl']);
        self::assertSame(
            'https://gewis.nl/user_forgot_password',
            $sent[0]->getContext()['passwordResetUrl'],
        );
        self::assertSame(
            self::SECRETARY_ADDRESS,
            $sent[0]->getReplyTo()[0]->getAddress(),
        );
        self::assertSame(
            self::SECRETARY_ADDRESS,
            $sent[1]->getReplyTo()[0]->getAddress(),
        );
    }

    public function testAMailAboutACheckoutThatExpiredLinksBackIntoCheckout(): void
    {
        $prospectiveMember = $this->prospectiveMember('ada@example.com');
        $prospectiveMember->setPaymentLink(new PaymentLink());

        $sent = [];
        $this->handler(
            $sent,
            null,
            $prospectiveMember,
        )->__invoke(new RegistrationUpdateEmail(
            RegistrationUpdate::CheckoutExpired,
            7001,
        ));

        self::assertCount(
            2,
            $sent,
        );
        self::assertSame(
            'Complete your GEWIS registration',
            $sent[0]->getSubject(),
        );
        self::assertSame(
            'database/email/checkout-expired.html.twig',
            $sent[0]->getHtmlTemplate(),
        );
        self::assertSame(
            'https://gewis.nl/join_checkout_restart?token=reissued-token',
            $sent[0]->getContext()['restartUrl'],
        );
        self::assertSame(
            'Membership payment expired: Ada Lovelace',
            $sent[1]->getSubject(),
        );
    }

    public function testARecordThatWasRemovedIsNotMailedAbout(): void
    {
        $sent = [];
        $this->handler(
            $sent,
            null,
            null,
        )->__invoke(new RegistrationUpdateEmail(
            RegistrationUpdate::CheckoutFailed,
            987654,
        ));

        self::assertSame(
            [],
            $sent,
        );
    }

    public function testAMemberWithoutAnAddressIsNotMailedAbout(): void
    {
        $sent = [];
        $this->handler(
            $sent,
            $this->member(null),
        )->__invoke(new RegistrationUpdateEmail(
            RegistrationUpdate::Welcome,
            8025,
        ));

        self::assertSame(
            [],
            $sent,
        );
    }

    /**
     * @param list<TemplatedEmail> $sent
     */
    private function handler(
        array &$sent,
        ?Member $member = null,
        ?ProspectiveMember $prospectiveMember = null,
    ): RegistrationUpdateEmailHandler {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$sent): void {
                $sent[] = $email;
            },
        );

        $members = self::createStub(MemberRepository::class);
        $members->method('findSimple')->willReturn($member);

        $prospectiveMembers = self::createStub(ProspectiveMemberRepository::class);
        $prospectiveMembers->method('find')->willReturn($prospectiveMember);

        $actionLinks = self::createStub(ActionLinkService::class);
        $actionLinks->method('reissue')->willReturn('reissued-token');

        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static function (string $route, array $parameters = []): string {
                $url = 'https://gewis.nl/' . $route;

                return isset($parameters['token'])
                    ? $url . '?token=' . $parameters['token']
                    : $url;
            },
        );

        return new RegistrationUpdateEmailHandler(
            $members,
            $prospectiveMembers,
            new Email(
                $mailer,
                'noreply@example.com',
                'GEWIS',
                self::SECRETARY_ADDRESS,
                'Secretary',
            ),
            $actionLinks,
            $urlGenerator,
            self::SECRETARY_ADDRESS,
            'Secretary',
        );
    }

    private function member(?string $email): Member
    {
        $member = new Member();
        $member->lidnr = 8025;
        $member->firstName = 'Ada';
        $member->middleName = '';
        $member->lastName = 'Lovelace';
        new ReflectionProperty(
            Member::class,
            'email',
        )->setValue(
            $member,
            $email,
        );

        return $member;
    }

    private function prospectiveMember(string $email): ProspectiveMember
    {
        $member = new ProspectiveMember();
        $member->lidnr = 7001;
        $member->email = $email;
        $member->firstName = 'Ada';
        $member->middleName = '';
        $member->lastName = 'Lovelace';

        return $member;
    }
}
