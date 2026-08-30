<?php

declare(strict_types=1);

namespace App\MessageHandler\Database;

use App\Entity\Application\Enums\Languages;
use App\Message\Database\GraduateRemovalRequested;
use App\Repository\Database\MemberRepository;
use App\Service\Application\Email as EmailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
class GraduateRemovalRequestedHandler
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly EmailService $emailService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailToSubscriptionAddress,
        private readonly string $mailToSubscriptionName,
    ) {
    }

    public function __invoke(GraduateRemovalRequested $message): void
    {
        $member = $this->memberRepository->findSimple($message->getLidnr());

        // Removed between the asking and now, which is the thing that was asked for.
        if (null === $member) {
            return;
        }

        $this->emailService->send(
            new Address(
                $this->mailToSubscriptionAddress,
                $this->mailToSubscriptionName,
            ),
            'Removal requested by a leaving member (' . $member->getLidnr() . ')',
            'database/email/graduate-removal-requested.html.twig',
            [
                'member' => $member,
                'url' => $this->urlGenerator->generate(
                    'member_show',
                    [
                        '_locale' => Languages::English->getLangParam(),
                        'lidnr' => $member->getLidnr(),
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
            $this->emailService->secretary(),
        );
    }
}
