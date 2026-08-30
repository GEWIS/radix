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
        $previous = null;

        foreach ($memberships as $membership) {
            if ($membership->getEndDate() < $membership->getStartDate()) {
                $errors[] = new MembershipError(
                    $member,
                    MembershipProblems::EndsBeforeItStarts,
                    $membership,
                );
            }

            if (null !== $previous) {
                if (
                    $previous->getStartDate()->getTimestamp() === $membership->getStartDate()->getTimestamp()
                ) {
                    $errors[] = new MembershipError(
                        $member,
                        MembershipProblems::StartsOnTheSameDay,
                        $previous,
                        $membership,
                    );
                } elseif ($previous->getEndDate() > $membership->getStartDate()) {
                    // Which of the two is the member's on the days both cover is not something the record answers.
                    $errors[] = new MembershipError(
                        $member,
                        MembershipProblems::Overlapping,
                        $previous,
                        $membership,
                    );
                }
            }

            $previous = $membership;
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
