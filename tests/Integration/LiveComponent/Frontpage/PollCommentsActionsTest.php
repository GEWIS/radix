<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Frontpage;

use App\Entity\Frontpage\Enums\PollCommentReactionType;
use App\Entity\Frontpage\Poll;
use App\Entity\Frontpage\PollComment;
use App\Entity\User\User;
use App\Repository\Frontpage\PollRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Frontpage\PollComments;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_map;

/**
 * The rest of the discussion: opening and cancelling a reply, refusing an empty one, and what a closed poll does to
 * writing and reacting. The reading side and the reactions themselves are covered next to this file.
 */
final class PollCommentsActionsTest extends DatabaseTestCase
{
    public function testAReplyCanBeOpenedAndCancelledWithNothingWritten(): void
    {
        $component = $this->component();
        $comment = $this->firstComment($component->poll);

        $component->startReply($comment->id ?? 0);
        self::assertSame(
            $comment->id,
            $component->replyTo,
        );
        $component->replyContent = 'Half-written';

        $component->cancelReply();

        self::assertNull($component->replyTo);
        self::assertSame(
            '',
            $component->replyContent,
        );
    }

    public function testACommentWithoutANameIsRefused(): void
    {
        $component = $this->component();
        $component->author = '   ';
        $component->content = 'Something worth saying';

        $component->post();

        self::assertNotNull($component->problem);
    }

    public function testAReplyWithoutTextIsRefused(): void
    {
        $component = $this->component();
        $comment = $this->firstComment($component->poll);
        $component->author = 'Anonymous Thirst';
        $component->replyContent = '   ';

        $component->reply($comment->id ?? 0);

        self::assertNotNull($component->problem);
        self::assertNull($component->replyTo);
    }

    public function testACommentThatIsNotThisThreadsCannotBeReactedToThroughIt(): void
    {
        $component = $this->component();

        $this->expectException(AccessDeniedException::class);
        $component->react(
            999999,
            PollCommentReactionType::Like->value,
        );
    }

    public function testAReactionThatIsNotOneIsRefused(): void
    {
        $component = $this->component();
        $comment = $this->firstComment($component->poll);

        $this->expectException(AccessDeniedException::class);
        $component->react(
            $comment->id ?? 0,
            'indifferent',
        );
    }

    public function testTheThreadOffersEveryReactionAndShowsTheThumbprints(): void
    {
        $component = $this->component();
        $comment = $this->firstComment($component->poll);

        self::assertSame(
            PollCommentReactionType::cases(),
            $component->reactionTypes(),
        );
        self::assertSame(
            [
                'like',
                'love',
                'insightful',
                'funny',
            ],
            array_map(
                static fn (PollCommentReactionType $reaction): string => $reaction->value,
                $component->reactionTypes(),
            ),
        );
        self::assertNull($component->myReaction($comment));
    }

    public function testOnlyTheBoardIsShownWhoWroteWhat(): void
    {
        self::assertFalse($this->component()->showsMembers());
        self::assertTrue($this->component(8025, ['ROLE_USER', 'ROLE_BOARD'])->showsMembers());
    }

    public function testAClosedPollIsNotOpenAndTakesNoComment(): void
    {
        $component = $this->componentFor($this->closedPoll());
        $component->author = 'Late Riser';
        $component->content = 'Better late than never';

        self::assertFalse($component->isOpen());

        $component->post();

        self::assertSame(
            'This poll is closed.',
            $component->problem,
        );
    }

    public function testAClosedPollTakesNoReactionEither(): void
    {
        $component = $this->componentFor($this->closedPoll());
        $comment = $this->firstComment($component->poll);

        $component->react(
            $comment->id ?? 0,
            PollCommentReactionType::Funny->value,
        );

        self::assertSame(
            'This poll is closed.',
            $component->problem,
        );
    }

    private function firstComment(Poll $poll): PollComment
    {
        $comment = $poll->getComments()->first();
        self::assertInstanceOf(
            PollComment::class,
            $comment,
        );

        return $comment;
    }

    /**
     * @param list<string> $roles
     */
    private function component(
        int $lidnr = 8030,
        array $roles = ['ROLE_USER'],
    ): PollComments {
        return $this->componentFor(
            $this->livePoll(),
            $lidnr,
            $roles,
        );
    }

    /**
     * @param list<string> $roles
     */
    private function componentFor(
        Poll $poll,
        int $lidnr = 8030,
        array $roles = ['ROLE_USER'],
    ): PollComments {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $roles,
        ));

        $component = self::getContainer()->get(PollComments::class);
        $component->poll = $poll;

        return $component;
    }

    private function livePoll(): Poll
    {
        $poll = self::getContainer()->get(PollRepository::class)->findActivePolls()[0] ?? null;
        self::assertInstanceOf(
            Poll::class,
            $poll,
        );

        return $poll;
    }

    private function closedPoll(): Poll
    {
        $poll = self::getContainer()->get(PollRepository::class)->findMostRecentlyClosed();
        self::assertInstanceOf(
            Poll::class,
            $poll,
            'The seed closes a poll.',
        );

        return $poll;
    }
}
