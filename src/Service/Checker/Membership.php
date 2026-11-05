<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Database\Enums\MembershipProblems;
use App\Entity\Database\Member as MemberModel;
use App\Entity\Database\Membership as MembershipModel;
use App\Repository\Checker\MemberRepository;
use App\ViewModel\Checker\MembershipError;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

use function count;
use function usort;

class Membership
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly MailerInterface $mailer,
        private readonly string $mailFromAddress,
        private readonly string $mailFromName,
        private readonly string $mailToCheckerResultAddress,
        private readonly string $mailToCheckerResultName,
    ) {
    }

    public function check(): void
    {
        $errors = [];

        foreach ($this->memberRepository->findAll() as $member) {
            foreach ($this->problemsOf($member) as $error) {
                $errors[] = $error;
            }
        }

        $this->sendMail($errors);
    }

    /**
     * A gap is not a problem: somebody can leave the association and come back.
     *
     * @return MembershipError[]
     */
    public function problemsOf(MemberModel $member): array
    {
        $memberships = $member->getMemberships()->toArray();

        usort(
            $memberships,
            static fn (MembershipModel $a, MembershipModel $b): int => $a->getStartDate() <=> $b->getStartDate(),
        );

        $errors = [];
        // Track the interval with the furthest end date, not just the previous membership.
        // A new membership may overlap an earlier one without being adjacent to it.
        $furthestEnd = null;
        $furthestEndMembership = null;

        foreach ($memberships as $membership) {
            if ($membership->endDate < $membership->getStartDate()) {
                $errors[] = new MembershipError(
                    $member,
                    MembershipProblems::EndsBeforeItStarts,
                    $membership,
                );
            }

            if (
                null !== $furthestEnd
                && $membership->getStartDate()->getTimestamp() >= $furthestEnd->getTimestamp()
            ) {
                // No overlap: this membership starts after the furthest end date.
                if (
                    $membership->endDate->getTimestamp() > $furthestEnd->getTimestamp()
                ) {
                    $furthestEnd = $membership->endDate;
                    $furthestEndMembership = $membership;
                }
            } elseif (null !== $furthestEnd) {
                // Starts before the furthest end date: check for overlaps.
                if (
                    $furthestEndMembership->getStartDate()->getTimestamp()
                    === $membership->getStartDate()->getTimestamp()
                ) {
                    $errors[] = new MembershipError(
                        $member,
                        MembershipProblems::StartsOnTheSameDay,
                        $furthestEndMembership,
                        $membership,
                    );
                } else {
                    $errors[] = new MembershipError(
                        $member,
                        MembershipProblems::Overlapping,
                        $furthestEndMembership,
                        $membership,
                    );
                }

                // Update the furthest end if this membership extends further.
                if ($membership->endDate->getTimestamp() > $furthestEnd->getTimestamp()) {
                    $furthestEnd = $membership->endDate;
                    $furthestEndMembership = $membership;
                }
            } else {
                $furthestEnd = $membership->endDate;
                $furthestEndMembership = $membership;
            }
        }

        return $errors;
    }

    /**
     * Sent even when there is nothing to report, so a silent week is a week that was checked.
     *
     * @param MembershipError[] $errors
     */
    private function sendMail(array $errors): void
    {
        $message = new TemplatedEmail()
            ->to(new Address($this->mailToCheckerResultAddress, $this->mailToCheckerResultName))
            ->from(new Address($this->mailFromAddress, $this->mailFromName))
            ->subject('Membership Checker Report')
            ->textTemplate('database/checker/membership-report.txt.twig')
            ->context([
                'errors' => $errors,
                'count' => count($errors),
            ]);

        $this->mailer->send($message);
    }
}
