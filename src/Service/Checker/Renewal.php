<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Application\Enums\Languages;
use App\Entity\Database\GraduateConversionLink as GraduateConversionLinkModel;
use App\Entity\Database\RenewalLink as RenewalLinkModel;
use App\Entity\Decision\OrganMember as OrganMemberModel;
use App\Repository\Checker\MemberRepository;
use App\Repository\Database\ActionLinkRepository;
use App\Repository\Decision\MemberRepository as ReportMemberRepository;
use App\Service\Application\Email as EmailService;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * Renewal class that takes care of renewing graduates
 * and converting memberships to graduates
 */
class Renewal
{
    public function __construct(
        private readonly ActionLinkRepository $actionLinkRepository,
        private readonly MemberRepository $memberRepository,
        private readonly ReportMemberRepository $reportMemberRepository,
        private readonly EmailService $emailService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Create an actionlink and send emails to expiring graduates
     * Emails are sent 45 days before expiry
     * A limit of 10 graduates is used; e.g. on a cronjob each hour this would mean 250 per day
     * Limiting to make sure the secretary does not get overwhelmed with questions regarding renewal.
     */
    public function sendRenewalGraduates(): void
    {
        $expiresWithin = new DateTimeImmutable()->add(new DateInterval('P45D'));
        $limit = 10;
        $graduates = $this->memberRepository->getExpiringGraduates(
            $expiresWithin,
            $limit,
        );

        foreach ($graduates as $graduate) {
            $renewalLink = $this->actionLinkRepository->createRenewalByMember($graduate);

            try {
                $this->sendRenewalEmail($renewalLink);
            } catch (Throwable $e) {
                $this->actionLinkRepository->remove($renewalLink);

                throw $e;
            }
        }
    }

    public function sendGraduateConversions(): void
    {
        $expiresWithin = new DateTimeImmutable()->add(new DateInterval('P45D'));
        $limit = 10;
        $members = $this->memberRepository->getExpiringConversions(
            $expiresWithin,
            $limit,
        );

        foreach ($members as $member) {
            $membership = $member->getCurrentOrLastMembership();

            if (null === $membership) {
                continue;
            }

            $link = new GraduateConversionLinkModel(
                $member,
                clone $membership->endDate,
            );
            $this->actionLinkRepository->persist($link);

            try {
                $this->sendGraduateConversionEmail($link);
            } catch (Throwable $e) {
                $this->actionLinkRepository->remove($link);

                throw $e;
            }
        }
    }

    private function sendGraduateConversionEmail(GraduateConversionLinkModel $link): void
    {
        $member = $link->member;
        $recipient = $member->getEmailRecipient();

        if (null === $recipient) {
            return;
        }

        $this->emailService->send(
            $recipient,
            'Your GEWIS membership is ending (' . $member->lidnr . ')',
            'database/email/graduate-conversion.html.twig',
            [
                'firstName' => $member->firstName,
                'currentExpiration' => $link->currentExpiration,
                // English page, and the token this link was just minted with: only its hash is stored.
                'url' => $this->urlGenerator->generate(
                    'join_graduate_claim',
                    [
                        '_locale' => Languages::English->getLangParam(),
                        'token' => $link->plainToken ?? throw new RuntimeException(
                            'Cannot write a conversion link that was not minted here',
                        ),
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
            $this->emailService->secretary(),
            bccReplyTo: true,
        );
    }

    private function sendRenewalEmail(RenewalLinkModel $link): void
    {
        $reportMember = $this->reportMemberRepository->findSimple($link->member->lidnr);
        $isInstalled = !$reportMember->getOrganInstallations()
            ->filter(static fn (OrganMemberModel $member) => $member->isCurrent())
            ->isEmpty();

        $this->emailService->send(
            $link->member->getEmailRecipient(),
            'Graduate Renewal (' . $link->member->lidnr . ')',
            'database/email/graduate-renewal.html.twig',
            [
                'firstName' => $link->member->firstName,
                'isInstalled' => $isInstalled,
                'currentExpiration' => $link->currentExpiration,
                'newExpiration' => $link->newExpiration,
                // English page, and the token this link was just generated with: only its hash is stored.
                'url' => $this->urlGenerator->generate(
                    'join_renew_claim',
                    [
                        '_locale' => Languages::English->getLangParam(),
                        'token' => $link->plainToken ?? throw new RuntimeException(
                            'Cannot send a renewal link that was not generated here',
                        ),
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
            $this->emailService->secretary(),
            // The secretary keeps a copy of both renewal messages, as they did when every templated e-mail was
            // blind-copied to the reply-to address. Unlike the registration e-mails there is no separate send here.
            bccReplyTo: true,
        );
    }

    public function sendRenewalSuccessEmail(RenewalLinkModel $link): void
    {
        $this->emailService->send(
            $link->member->getEmailRecipient(),
            'Graduate Renewal (' . $link->member->lidnr . ')',
            'database/email/graduate-renewal-success.html.twig',
            [
                'firstName' => $link->member->firstName,
                'oldExpiration' => $link->currentExpiration,
                'newExpiration' => $link->newExpiration,
            ],
            $this->emailService->secretary(),
            bccReplyTo: true,
        );
    }
}
