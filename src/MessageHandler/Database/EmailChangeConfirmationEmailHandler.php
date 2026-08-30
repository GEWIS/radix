<?php

declare(strict_types=1);

namespace App\MessageHandler\Database;

use App\Entity\Application\Enums\Languages;
use App\Message\Database\EmailChangeConfirmationEmail;
use App\Repository\Database\MemberRepository;
use App\Service\Application\Email as EmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
class EmailChangeConfirmationEmailHandler
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly EmailService $emailService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(EmailChangeConfirmationEmail $message): void
    {
        $member = $this->memberRepository->findSimple($message->getLidnr());

        // Gone between the asking and now: there is no longer anybody to confirm anything for.
        if (null === $member) {
            return;
        }

        $this->emailService->send(
            new Address(
                $message->getNewEmail(),
                $member->getFullName(),
            ),
            'Confirm your e-mail address (' . $member->getLidnr() . ')',
            'database/email/email-change-confirm.html.twig',
            [
                'firstName' => $member->getFirstName(),
                'newEmail' => $message->getNewEmail(),
                // The message is in English, so ask for the English page rather than whatever the router holds.
                'url' => $this->urlGenerator->generate(
                    'user_email_change_claim',
                    [
                        '_locale' => Languages::English->getLangParam(),
                        'token' => $message->getToken(),
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
            $this->emailService->secretary(),
        );
    }
}
