<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener\Application;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Application\RevisionInterface;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Repository\Activity\ActivityRevisionRepository;
use App\Security\User\MfaEnforcementSwitch;
use App\Tests\Integration\DatabaseTestCase;
use DateTime;
use Override;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Handing a revision in writes to the office that reviews it, so that something waiting is noticed by the officer
 * whose job it is rather than by whoever signs in next. The exception is the officer submitting their own work, which
 * is what made the old website's version of these easy to ignore.
 */
final class MailReviewersOnRevisionSubmissionTest extends DatabaseTestCase
{
    #[Override]
    protected function tearDown(): void
    {
        MfaEnforcementSwitch::setEnabled(true);

        parent::tearDown();
    }

    public function testSubmittingWritesToTheOfficeThatReviewsIt(): void
    {
        $draft = $this->draft();
        $this->authenticateAuthorOf(
            $draft,
            ['ROLE_USER'],
        );

        $this->submit($draft);

        self::assertNotSame(
            [],
            $this->messagesTo('internal@example.com'),
            'An activity is the internal affairs officer\'s to look at.',
        );
    }

    /**
     * The board reviews activities, so a board member handing one in is telling themselves.
     */
    public function testNothingIsSentWhenTheSubmitterReviewsThisThemselves(): void
    {
        // Without this a board member's account answers with no ROLE_BOARD at all: enforcement is on by default and
        // strips it from anyone who has not enrolled in multi-factor authentication, which the seed's members have
        // not. What is under test is what happens when the submitter does hold the role.
        MfaEnforcementSwitch::setEnabled(false);

        $draft = $this->draft();
        $draft->setAuthor($this->aBoardMember());
        $this->authenticateAuthorOf(
            $draft,
            ['ROLE_USER'],
        );

        $this->submit($draft);

        self::assertSame(
            [],
            $this->messagesTo('internal@example.com'),
        );
    }

    /**
     * The subjects of everything addressed to one mailbox. The collector records a message when it is queued and
     * again when it is sent, so the same one appears more than once; what matters here is whether it went at all.
     *
     * @return string[]
     */
    private function messagesTo(string $address): array
    {
        $subjects = [];

        foreach (self::getMailerMessages() as $message) {
            if (!$message instanceof Email) {
                continue;
            }

            foreach ($message->getTo() as $recipient) {
                if ($address !== $recipient->getAddress()) {
                    continue;
                }

                $subjects[] = $message->getSubject() ?? '';
            }
        }

        return $subjects;
    }

    private function submit(ActivityRevision $draft): void
    {
        $this->workflow($draft)->apply(
            $draft,
            'submit',
        );
        $this->entityManager->flush();
    }

    /**
     * Any draft the seed left behind that has not happened yet, which is what the workflow will let through.
     */
    private function draft(): ActivityRevision
    {
        foreach (self::getContainer()->get(ActivityRevisionRepository::class)->findAll() as $revision) {
            if (
                RevisionStatus::Draft !== $revision->getStatus()
                || $revision->getActivity()->getBeginTime() < new DateTime()
            ) {
                continue;
            }

            return $revision;
        }

        self::fail('The seed is expected to contain a draft activity that has not happened yet.');
    }

    /**
     * A member the association currently has installed on its board, which is the role that reviews an activity.
     */
    private function aBoardMember(): Member
    {
        foreach ($this->entityManager->getRepository(Member::class)->findAll() as $member) {
            // An account as well as an installation: the roles a submission is weighed against are read off the
            // account, so a board member without one would be treated as any other author.
            if (
                !$member->isBoardMember()
                || null === $this->entityManager->find(
                    User::class,
                    $member->getLidnr(),
                )
            ) {
                continue;
            }

            return $member;
        }

        self::fail('The seed is expected to contain a board member with an account.');
    }

    /**
     * @param string[] $roles
     */
    private function authenticateAuthorOf(
        ActivityRevision $draft,
        array $roles,
    ): void {
        $author = $draft->getAuthor();
        self::assertNotNull($author);

        $user = $this->entityManager->find(
            User::class,
            $author->getLidnr(),
        );
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $roles,
        ));
    }

    private function workflow(RevisionInterface $revision): WorkflowInterface
    {
        return self::getContainer()->get(Registry::class)->get(
            $revision,
            'revision',
        );
    }
}
