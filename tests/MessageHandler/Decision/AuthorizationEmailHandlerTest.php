<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Authorization;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\Member;
use App\Message\Decision\AuthorizationCreatedEmail;
use App\Message\Decision\AuthorizationRevokedEmail;
use App\MessageHandler\Decision\AuthorizationEmailHandler;
use App\Repository\Decision\AuthorizationRepository;
use App\Repository\Decision\MeetingRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

use function array_map;

/**
 * A GMM authorization concerns two members, and both are mailed: the one who gave it and the one who received it.
 * The meeting is named by ordinal number and date, and a member without an address is skipped.
 */
final class AuthorizationEmailHandlerTest extends TestCase
{
    public function testAGrantedAuthorizationIsMailedToBothMembers(): void
    {
        $sent = [];
        $this->handler($sent)->handleCreated(new AuthorizationCreatedEmail(5));

        self::assertCount(
            2,
            $sent,
        );
        self::assertSame(
            [
                'grantee@example.com',
                'grantor@example.com',
            ],
            array_map(
                static fn (TemplatedEmail $email): string => $email->getTo()[0]->getAddress(),
                $sent,
            ),
        );
        self::assertSame(
            [
                'GMM authorisation from Grace Hopper',
                'GMM authorisation for Ada Lovelace',
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
            'emails/decision/authorisation-grantee.html.twig',
            $sent[0]->getHtmlTemplate(),
        );
        self::assertSame(
            'emails/decision/authorisation-grantor.html.twig',
            $sent[1]->getHtmlTemplate(),
        );
        self::assertSame(
            '12th',
            $sent[0]->getContext()['meetingNumber'],
        );
        self::assertSame(
            'January 15, 2026',
            $sent[0]->getContext()['meetingDate'],
        );
        self::assertSame(
            'Grace Hopper',
            $sent[0]->getContext()['grantor']->getFullName(),
        );
        self::assertSame(
            'Ada Lovelace',
            $sent[0]->getContext()['grantee']->getFullName(),
        );
    }

    public function testAMemberWithoutAnEmailIsSkippedRatherThanFailingTheOther(): void
    {
        $sent = [];
        $this->handler(
            $sent,
            $this->authorization(granteeEmail: null),
        )->handleCreated(new AuthorizationCreatedEmail(5));

        self::assertCount(
            1,
            $sent,
        );
        self::assertSame(
            'grantor@example.com',
            $sent[0]->getTo()[0]->getAddress(),
        );

        $sent = [];
        $this->handler(
            $sent,
            $this->authorization(grantorEmail: null),
        )->handleCreated(new AuthorizationCreatedEmail(5));

        self::assertCount(
            1,
            $sent,
        );
        self::assertSame(
            'grantee@example.com',
            $sent[0]->getTo()[0]->getAddress(),
        );
    }

    public function testAnAuthorizationThatWasRemovedIsNotMailed(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $handler = new AuthorizationEmailHandler(
            $mailer,
            $this->authorizations(null),
            $this->meetings(null),
        );
        $handler->handleCreated(new AuthorizationCreatedEmail(404));
        $handler->handleRevoked(new AuthorizationRevokedEmail(404));
    }

    public function testARevokedAuthorizationIsMailedToTheHolder(): void
    {
        $sent = [];
        $this->handler($sent)->handleRevoked(new AuthorizationRevokedEmail(5));

        self::assertCount(
            1,
            $sent,
        );
        self::assertSame(
            'grantee@example.com',
            $sent[0]->getTo()[0]->getAddress(),
        );
        self::assertSame(
            'GMM authorisation from Grace Hopper revoked',
            $sent[0]->getSubject(),
        );
        self::assertSame(
            'emails/decision/authorisation-revoked.html.twig',
            $sent[0]->getHtmlTemplate(),
        );
        self::assertSame(
            '12th',
            $sent[0]->getContext()['meetingNumber'],
        );
    }

    public function testARevocationForAMemberWithoutAnEmailIsNotMailed(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $handler = new AuthorizationEmailHandler(
            $mailer,
            $this->authorizations($this->authorization(granteeEmail: null)),
            $this->meetings($this->meeting()),
        );
        $handler->handleRevoked(new AuthorizationRevokedEmail(5));
    }

    public function testAMeetingThatIsNotInTheRegisterLeavesOnlyTheOrdinal(): void
    {
        $sent = [];
        $handler = new AuthorizationEmailHandler(
            $this->capturingMailer($sent),
            $this->authorizations($this->authorization()),
            $this->meetings(null),
        );

        $handler->handleCreated(new AuthorizationCreatedEmail(5));

        self::assertSame(
            '',
            $sent[0]->getContext()['meetingDate'],
        );
        self::assertSame(
            '12th',
            $sent[0]->getContext()['meetingNumber'],
        );
    }

    /**
     * @param list<TemplatedEmail> $sent
     */
    private function handler(
        array &$sent,
        ?Authorization $authorization = null,
    ): AuthorizationEmailHandler {
        return new AuthorizationEmailHandler(
            $this->capturingMailer($sent),
            $this->authorizations($authorization ?? $this->authorization()),
            $this->meetings($this->meeting()),
        );
    }

    /**
     * @param list<TemplatedEmail> $sent
     */
    private function capturingMailer(array &$sent): MailerInterface
    {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$sent): void {
                $sent[] = $email;
            },
        );

        return $mailer;
    }

    private function authorizations(?Authorization $authorization): AuthorizationRepository
    {
        $repository = self::createStub(AuthorizationRepository::class);
        $repository->method('find')->willReturn($authorization);

        return $repository;
    }

    private function meetings(?Meeting $meeting): MeetingRepository
    {
        $repository = self::createStub(MeetingRepository::class);
        $repository->method('findMeeting')->willReturnCallback(
            static fn (MeetingTypes $type, int $number): ?Meeting => MeetingTypes::ALV === $type && 12 === $number
                ? $meeting
                : null,
        );

        return $repository;
    }

    private function authorization(
        ?string $granteeEmail = 'grantee@example.com',
        ?string $grantorEmail = 'grantor@example.com',
    ): Authorization {
        $authorization = new Authorization();
        $authorization->authorizer = $this->member(
            8025,
            $grantorEmail,
            'Grace',
            'Hopper',
        );
        $authorization->recipient = $this->member(
            8026,
            $granteeEmail,
            'Ada',
            'Lovelace',
        );
        $authorization->meetingNumber = 12;
        $authorization->createdAt = new DateTimeImmutable('2026-01-15');

        return $authorization;
    }

    private function member(
        int $lidnr,
        ?string $email,
        string $firstName,
        string $lastName,
    ): Member {
        $member = new Member();
        $member->lidnr = $lidnr;
        $member->email = $email;
        $member->firstName = $firstName;
        $member->middleName = '';
        $member->lastName = $lastName;

        return $member;
    }

    private function meeting(): Meeting
    {
        $meeting = new Meeting();
        $meeting->date = new DateTimeImmutable('2026-01-15');

        return $meeting;
    }
}
