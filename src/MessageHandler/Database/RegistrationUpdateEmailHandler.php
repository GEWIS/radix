<?php

declare(strict_types=1);

namespace App\MessageHandler\Database;

use App\Entity\Database\ProspectiveMember;
use App\Message\Database\RegistrationUpdateEmail;
use App\Repository\Database\MemberRepository;
use App\Repository\Database\ProspectiveMemberRepository;
use App\Service\Application\Email as EmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the registration update to the (prospective) member and to the secretary. Runs in a worker; the mail is
 * always English, so nothing here reads a locale.
 */
#[AsMessageHandler]
class RegistrationUpdateEmailHandler
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly ProspectiveMemberRepository $prospectiveMemberRepository,
        private readonly EmailService $emailService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailToSubscriptionAddress,
        private readonly string $mailToSubscriptionName,
    ) {
    }

    public function __invoke(RegistrationUpdateEmail $message): void
    {
        $type = $message->getType();
        $member = $type->isAboutMember()
            ? $this->memberRepository->findSimple($message->getLidnr())
            : $this->prospectiveMemberRepository->find($message->getLidnr());

        // Gone between the dispatch and now: a registration that lapsed and was cleaned up, or a member removed
        // again. There is no longer anything to write about, nor anyone to write to.
        if (null === $member) {
            return;
        }

        $recipientEmail = $member->getEmail();

        if (null === $recipientEmail) {
            return;
        }

        // What the templates say, rather than the record they say it about: the name to greet, the number that was
        // assigned, the link back into a checkout that did not finish, and where a confirmed member sets their first
        // password (no account exists until they do, so a reset request is the way in).
        $paymentLink = $member instanceof ProspectiveMember
            ? $member->getPaymentLink()
            : null;
        $context = [
            'member' => $member,
            'firstName' => $member->getFirstName(),
            'lidnr' => $member->getLidnr(),
            'restartUrl' => null === $paymentLink
                ? null
                : $this->urlGenerator->generate(
                    'join_checkout_restart_short',
                    ['token' => $paymentLink->getToken()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            'passwordResetUrl' => $this->urlGenerator->generate(
                'user_forgot_password',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];

        $secretary = new Address(
            $this->mailToSubscriptionAddress,
            $this->mailToSubscriptionName,
        );

        // Always try to send the e-mail to the prospective member before sending to the secretary. The secretary can
        // look in the database, the prospective member cannot.
        $this->emailService->send(
            new Address(
                $recipientEmail,
                $member->getFullName(),
            ),
            $type->subject(),
            $type->template(),
            $context,
            $secretary,
        );

        $this->emailService->send(
            $secretary,
            $type->secretarySubject($member->getFullName()),
            $type->template(),
            $context,
            $secretary,
        );
    }
}
