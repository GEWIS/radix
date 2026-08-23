<?php

declare(strict_types=1);

namespace App\EventListener\Application;

use App\Entity\Application\RevisionInterface;
use App\Repository\User\UserRepository;
use App\Security\User\Firewall;
use App\Service\Application\Email;
use App\Service\Application\RevisionNotificationRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;

use function in_array;

/**
 * Writes to the office that answers for a domain when one of its revisions is handed in.
 *
 * {@see NotifyOnRevisionSubmissionListener} already tells whoever holds the reviewing role, but only once they sign
 * in. This reaches the mailbox, so that something waiting is noticed by the officer whose job it is rather than by
 * whoever happens to log in next.
 *
 * Nothing is sent when the person who handed it in reviews this domain themselves: they know already, and the old
 * website's habit of mailing an officer about their own submission is what made these easy to ignore.
 */
#[AsEventListener(event: 'workflow.revision.entered.submitted')]
final readonly class MailReviewersOnRevisionSubmissionListener
{
    public function __construct(
        private RevisionNotificationRegistry $notifications,
        private UserRepository $userRepository,
        private RoleHierarchyInterface $roleHierarchy,
        private Email $email,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param EnteredEvent<object> $event
     */
    public function __invoke(EnteredEvent $event): void
    {
        $revision = $event->getSubject();
        if (!$revision instanceof RevisionInterface) {
            return;
        }

        $id = $revision->getId();
        $notification = $this->notifications->for($revision);
        if (
            null === $id
            || null === $notification
        ) {
            return;
        }

        $reviewsThis = $this->submitterReviewsThis(
            $revision,
            $notification->audienceRole($revision)->value,
        );
        if ($reviewsThis) {
            return;
        }

        $type = $notification->awaitingReviewType($revision);
        $subject = $notification->reviewerMailSubject($revision);
        $url = $this->urlGenerator->generate(
            $type->route(Firewall::Main),
            $type->routeParameters($id),
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        foreach ($notification->reviewerMailboxes($revision) as $mailbox) {
            $this->email->send(
                $mailbox,
                $subject,
                'emails/application/revision-awaiting-review.html.twig',
                [
                    'subject' => $subject,
                    'author' => $revision->getAuthorDisplayName(),
                    'revisionNumber' => $revision->getRevisionNumber(),
                    'url' => $url,
                ],
            );
        }
    }

    /**
     * Whether whoever handed this in is one of the people it would be sent to. A company user never is: the roles
     * that review a domain are the association's, and a company account holds none of them.
     */
    private function submitterReviewsThis(
        RevisionInterface $revision,
        string $role,
    ): bool {
        $author = $revision->getAuthor();
        if (null === $author) {
            return false;
        }

        $user = $this->userRepository->find($author->getLidnr());
        if (null === $user) {
            return false;
        }

        return in_array(
            $role,
            $this->roleHierarchy->getReachableRoleNames($user->getRoles()),
            true,
        );
    }
}
