<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\Application\AbstractRevisionComment;
use App\Entity\Application\RevisionInterface;
use App\Entity\User\CompanyUser;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * What a review screen actually does to a revision: starting a draft off it, deciding on it, discarding that draft,
 * and adding to its thread. Every domain that reviews something drives these, so they live here rather than being
 * re-sequenced by each controller.
 *
 * Each is one unit of work. A decision and the feedback typed alongside it commit together, because a rejection whose
 * reason did not save is a rejection nobody can answer; a discard commits with the edit lock it releases, so the
 * aggregate is never left locked against a draft that is gone.
 *
 * The pieces this composes — {@see RevisionReviser}, {@see RevisionDiscarder}, the state machine — deliberately do not
 * flush, so that the batch callers that drive them over many revisions still commit in one go.
 */
final readonly class RevisionReviewService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private RevisionDiscarder $revisionDiscarder,
        private RevisionReviser $revisionReviser,
        #[Target('revisionStateMachine')]
        private WorkflowInterface $revisionStateMachine,
    ) {
    }

    /**
     * Whether this decision is open to be taken at all. Asked before the reader is put through a sudo prompt for it,
     * so that an action the workflow would refuse is refused first.
     */
    public function canApply(
        RevisionInterface $revision,
        string $transition,
    ): bool {
        return $this->revisionStateMachine->can(
            $revision,
            $transition,
        );
    }

    /**
     * Take the decision, together with whatever was typed alongside it.
     */
    public function applyTransition(
        RevisionInterface $revision,
        User|CompanyUser $actor,
        string $transition,
        string $message = '',
    ): void {
        if ('' !== $message) {
            $this->entityManager->persist($this->newComment(
                $revision,
                $actor,
                $message,
            ));
        }

        $this->revisionStateMachine->apply(
            $revision,
            $transition,
        );

        $this->entityManager->flush();
    }

    /**
     * Start a new draft off what is live, which is the only way something already decided on is changed. Returns the
     * draft, which is what the caller sends the author to.
     */
    public function startDraft(
        RevisionInterface $revision,
        User|CompanyUser $author,
    ): RevisionInterface {
        $draft = $this->revisionReviser->spawnDraft(
            $revision,
            $author,
        );

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    /**
     * Throw the draft away and point its aggregate back at the version that is live.
     */
    public function discard(RevisionInterface $revision): void
    {
        $this->revisionDiscarder->discardToLive($revision);

        $this->entityManager->flush();
    }

    /**
     * Add to the revision's thread, outside of any decision.
     */
    public function comment(
        RevisionInterface $revision,
        User|CompanyUser $actor,
        string $body,
    ): void {
        $this->entityManager->persist($this->newComment(
            $revision,
            $actor,
            $body,
        ));

        $this->entityManager->flush();
    }

    /**
     * A thread entry on this revision, authored by whichever kind of principal is signed in.
     */
    private function newComment(
        RevisionInterface $revision,
        User|CompanyUser $actor,
        string $body,
    ): AbstractRevisionComment {
        $class = $revision->getCommentClass();

        $comment = new $class();
        $comment->attachTo($revision);
        $comment->setBody($body);

        if ($actor instanceof User) {
            $comment->setAuthor($actor);
        } else {
            $comment->setAuthorCompanyUser($actor);
        }

        return $comment;
    }
}
