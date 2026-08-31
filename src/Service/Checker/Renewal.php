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
use DateTime;
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
        $expiresWithin = new DateTime();
        $expiresWithin->add(new DateInterval('P45D'));
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
        $expiresWithin = new DateTime();
        $expiresWithin->add(new DateInterval('P45D'));
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
                clone $membership->getEndDate(),
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
        $member = $link->getMember();
        $recipient = $member->getEmailRecipient();

        if (null === $recipient) {
            return;
        }

        $this->emailService->send(
            $recipient,
            'Your GEWIS membership is ending (' . $member->getLidnr() . ')',
            'database/email/graduate-conversion.html.twig',
            [
                'firstName' => $member->getFirstName(),
                'currentExpiration' => $link->getCurrentExpiration(),
                // English page, and the token this link was just minted with: only its hash is stored.
                'url' => $this->urlGenerator->generate(
                    'join_graduate_claim',
                    [
                        '_locale' => Languages::English->getLangParam(),
                        'token' => $link->getPlainToken() ?? throw new RuntimeException(
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
        $reportMember = $this->reportMemberRepository->findSimple($link->getMember()->getLidnr());
        $isInstalled = !$reportMember->getOrganInstallations()
            ->filter(static fn (OrganMemberModel $member) => $member->isCurrent())
            ->isEmpty();

        $this->emailService->send(
            $link->getMember()->getEmailRecipient(),
            'Graduate Renewal (' . $link->getMember()->getLidnr() . ')',
            'database/email/graduate-renewal.html.twig',
            [
                'firstName' => $link->getMember()->getFirstName(),
                'isInstalled' => $isInstalled,
                'currentExpiration' => $link->getCurrentExpiration(),
                'newExpiration' => $link->getNewExpiration(),
                // English page, and the token this link was just minted with: only its hash is stored.
                'url' => $this->urlGenerator->generate(
                    'join_renew_claim',
                    [
                        '_locale' => Languages::English->getLangParam(),
                        'token' => $link->getPlainToken() ?? throw new RuntimeException(
                            'Cannot write a renewal link that was not minted here',
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
            $link->getMember()->getEmailRecipient(),
            'Graduate Renewal (' . $link->getMember()->getLidnr() . ')',
            'database/email/graduate-renewal-success.html.twig',
            [
                'firstName' => $link->getMember()->getFirstName(),
                'oldExpiration' => $link->getCurrentExpiration(),
                'newExpiration' => $link->getNewExpiration(),
            ],
            $this->emailService->secretary(),
            bccReplyTo: true,
        );
    }
}
