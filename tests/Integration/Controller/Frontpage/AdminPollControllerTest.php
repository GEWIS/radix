<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Frontpage;

use App\Controller\Frontpage\AdminPollController;
use App\Entity\Frontpage\Poll;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function strval;

/**
 * The board's poll overview. Taking a poll down closes it without removing what was asked or answered, so the action
 * only moves the closing date.
 */
final class AdminPollControllerTest extends DatabaseTestCase
{
    public function testTheOverviewShowsThePolls(): void
    {
        $this->authenticateAsBoard();

        self::assertSame(
            Response::HTTP_OK,
            $this->controller()->index()->getStatusCode(),
        );
    }

    public function testTakingAPollDownClosesItAndKeepsIt(): void
    {
        $this->authenticateAsBoard();

        $poll = $this->activePoll();
        $id = $poll->id;
        self::assertTrue($poll->isActive());

        $response = $this->controller()->delete($poll);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringEndsWith(
            '/admin/polls',
            strval($response->headers->get('Location')),
        );
        self::assertFalse($poll->isActive());
        self::assertSame(
            new DateTimeImmutable('today')->format('Y-m-d'),
            $poll->expiryDate?->format('Y-m-d'),
        );
        self::assertSame(
            $poll,
            $this->entityManager->getRepository(Poll::class)->find($id),
        );
    }

    private function controller(): AdminPollController
    {
        return self::getContainer()->get(AdminPollController::class);
    }

    private function activePoll(): Poll
    {
        $poll = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(
                Poll::class,
                'p',
            )
            ->join(
                'p.creator',
                'c',
            )
            ->where('c.lidnr = :creator')
            ->andWhere('p.expiryDate > CURRENT_DATE()')
            ->setParameter(
                'creator',
                8005,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Poll::class,
            $poll,
            'The seed is expected to contain a running poll.',
        );

        return $poll;
    }

    private function authenticateAsBoard(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            [UserRoles::Board->value],
        ));

        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
