<?php

declare(strict_types=1);

namespace App\MessageHandler\Database;

use App\Message\Database\EmailChangedNoticeEmail;
use App\Repository\Database\MemberRepository;
use App\Service\Application\Email as EmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
class EmailChangedNoticeEmailHandler
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly EmailService $emailService,
    ) {
    }

    public function __invoke(EmailChangedNoticeEmail $message): void
    {
        $member = $this->memberRepository->findSimple($message->getLidnr());

        if (null === $member) {
            return;
        }

        $this->emailService->send(
            new Address(
                $message->getPreviousEmail(),
                $member->getFullName(),
            ),
            'Your e-mail address was changed (' . $member->getLidnr() . ')',
            'database/email/email-changed.html.twig',
            [
                'firstName' => $member->getFirstName(),
                'previousEmail' => $message->getPreviousEmail(),
                'newEmail' => $message->getNewEmail(),
            ],
            $this->emailService->secretary(),
        );
    }
}
