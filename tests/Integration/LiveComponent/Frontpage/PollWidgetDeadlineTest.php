<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Frontpage;

use App\Entity\Frontpage\Poll;
use App\Entity\User\User;
use App\Repository\Frontpage\PollRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Frontpage\PollWidget;
use LogicException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * How long a poll still runs, and what the panel does when it was mounted without a poll to show. The answering itself
 * and the paging are covered next to this file.
 */
final class PollWidgetDeadlineTest extends DatabaseTestCase
{
    public function testARunningPollSaysHowManyDaysAreLeft(): void
    {
        $widget = $this->widget($this->livePoll());

        $days = $widget->daysLeft();

        self::assertNotNull($days);
        self::assertGreaterThanOrEqual(
            0,
            $days,
        );
        self::assertTrue($widget->canAnswer());
    }

    public function testAPollThatHasClosedHasNoDaysLeftAndTakesNoAnswer(): void
    {
        $widget = $this->widget($this->closedPoll());

        self::assertNull($widget->daysLeft());
        self::assertFalse($widget->canAnswer());
    }

    public function testAPollWithoutAnExpiryIsNotCountedDown(): void
    {
        $poll = $this->livePoll();
        $poll->expiryDate = null;

        $widget = $this->widget($poll);

        self::assertNull($widget->daysLeft());
        self::assertFalse($poll->isActive());
    }

    public function testAPanelMountedWithoutAPollSaysSo(): void
    {
        $widget = self::getContainer()->get(PollWidget::class);

        $this->expectException(LogicException::class);
        $widget->poll();
    }

    private function widget(Poll $poll): PollWidget
    {
        $user = $this->entityManager->getRepository(User::class)->find(8110);
        self::assertInstanceOf(
            User::class,
            $user,
        );
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            ['ROLE_USER'],
        ));

        $widget = self::getContainer()->get(PollWidget::class);
        $widget->polls = [$poll];

        return $widget;
    }

    private function livePoll(): Poll
    {
        $poll = self::getContainer()->get(PollRepository::class)->findActivePolls()[0] ?? null;
        self::assertInstanceOf(
            Poll::class,
            $poll,
        );
        self::assertNotNull($poll->expiryDate);

        return $poll;
    }

    private function closedPoll(): Poll
    {
        $poll = self::getContainer()->get(PollRepository::class)->findMostRecentlyClosed();
        self::assertInstanceOf(
            Poll::class,
            $poll,
        );

        return $poll;
    }
}
