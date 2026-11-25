<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\AdminController;
use App\Entity\Activity\Activity;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The board's cancel / un-cancel and unpublish / re-publish lifecycle actions on an approved activity. Like
 * {@see AdminApprovalControllerTest}, the actions are invoked directly (the app's session guard force-logs-out any
 * synthetic browser session), so this pins the in-method guards and state changes; the {@code #[IsGranted]} board gate
 * and CSRF are enforced by the framework at the HTTP boundary and covered by the browser-level suite.
 */
final class AdminControllerTest extends DatabaseTestCase
{
    public function testCancelAndUncancelAnApprovedActivity(): void
    {
        $activity = $this->anUpcomingApprovedActivity();
        self::assertFalse($activity->isCancelled());

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->cancel(
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertTrue($activity->isCancelled());
        self::assertTrue($activity->isFrozen());
        self::assertNotNull($activity->cancelledBy);

        $this->controller()->uncancel($activity);

        self::assertFalse($activity->isCancelled());
        self::assertFalse($activity->isFrozen());
        self::assertNull($activity->cancelledBy);
    }

    public function testUnpublishAndRepublishAnApprovedActivity(): void
    {
        $activity = $this->anApprovedActivity();
        self::assertFalse($activity->isUnpublished());

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->controller()->unpublish(
            $this->user(8025),
            $activity,
        );

        self::assertTrue($activity->isUnpublished());
        self::assertTrue($activity->isFrozen());
        self::assertNotNull($activity->unpublishedBy);

        $this->controller()->republish($activity);

        self::assertFalse($activity->isUnpublished());
        self::assertFalse($activity->isFrozen());
        self::assertNull($activity->unpublishedBy);
    }

    public function testCancelIsRefusedForAnActivityWithoutALiveRevision(): void
    {
        $activity = $this->aNeverApprovedActivity();

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->controller()->cancel(
            $this->user(8025),
            $activity,
        );

        // No live revision means there is nothing published to cancel; the guard refuses and leaves it untouched.
        self::assertFalse($activity->isCancelled());
    }

    /**
     * An activity that has taken place cannot be cancelled. Un-cancelling one still can be, so an activity cancelled
     * before it ended can still be reversed afterwards.
     */
    public function testCancelIsRefusedForAnActivityThatHasTakenPlace(): void
    {
        $activity = $this->anApprovedActivity();
        $this->moveIntoThePast($activity);

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->controller()->cancel(
            $this->user(8025),
            $activity,
        );

        self::assertFalse($activity->isCancelled());
    }

    public function testUncancelIsStillAllowedForAnActivityThatHasTakenPlace(): void
    {
        $activity = $this->anUpcomingApprovedActivity();

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->controller()->cancel(
            $this->user(8025),
            $activity,
        );
        $this->moveIntoThePast($activity);

        $this->controller()->uncancel($activity);

        self::assertFalse($activity->isCancelled());
    }

    /**
     * Unpublishing takes an activity out of public view, which is as meaningful once it has happened as before.
     */
    public function testUnpublishIsStillAllowedForAnActivityThatHasTakenPlace(): void
    {
        $activity = $this->anApprovedActivity();
        $this->moveIntoThePast($activity);

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->controller()->unpublish(
            $this->user(8025),
            $activity,
        );

        self::assertTrue($activity->isUnpublished());
    }

    public function testUncancelIsRefusedWhenNotCancelled(): void
    {
        $activity = $this->anApprovedActivity();

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->controller()->uncancel($activity);

        self::assertFalse($activity->isCancelled());
    }

    public function testUnpublishIsRefusedWithoutALiveRevision(): void
    {
        $activity = $this->aNeverApprovedActivity();

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $this->controller()->unpublish(
            $this->user(8025),
            $activity,
        );

        self::assertFalse($activity->isUnpublished());
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testUnpublishIsRefusedWhenAlreadyUnpublished(): void
    {
        $activity = $this->anActivityNamed('Unpublished Workshop');
        self::assertTrue($activity->isUnpublished());

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $this->controller()->unpublish(
            $this->user(8025),
            $activity,
        );

        self::assertTrue($activity->isUnpublished());
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testRepublishIsRefusedWhenNotUnpublished(): void
    {
        $activity = $this->anApprovedActivity();
        self::assertFalse($activity->isUnpublished());

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $this->controller()->republish($activity);

        self::assertFalse($activity->isUnpublished());
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    /**
     * A rejected revision is reopened into a fresh draft, so its author can try again.
     */
    public function testARejectedActivityCanBeReopened(): void
    {
        $activity = $this->anActivityNamed('Casino Night');
        $rejected = $activity->getCurrentRevision();
        self::assertNotNull($rejected);

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->reopen(
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/admin/activities/' . $activity->id . '/edit',
            $response->getTargetUrl(),
        );
        self::assertNotSame(
            $rejected,
            $activity->getCurrentRevision(),
        );
        self::assertSame(
            RevisionStatus::Draft,
            $activity->getCurrentRevision()?->getStatus(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    public function testReopenIsRefusedForAnActivityThatHasTakenPlace(): void
    {
        $activity = $this->anActivityNamed('Movie Night');
        $this->moveIntoThePast($activity);
        $current = $activity->getCurrentRevision();

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $this->controller()->reopen(
            $this->user(8025),
            $activity,
        );

        self::assertSame(
            $current,
            $activity->getCurrentRevision(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testReopenIsRefusedForAClosedRevision(): void
    {
        $activity = $this->anActivityNamed('Casino Night');
        $current = $activity->getCurrentRevision();
        self::assertNotNull($current);
        $current->setStatus(RevisionStatus::Closed);

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $this->controller()->reopen(
            $this->user(8025),
            $activity,
        );

        self::assertSame(
            $current,
            $activity->getCurrentRevision(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testReopenIsRefusedForACancelledActivity(): void
    {
        $activity = $this->anActivityNamed('Cancelled Gala');
        $current = $activity->getCurrentRevision();

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $this->controller()->reopen(
            $this->user(8025),
            $activity,
        );

        self::assertSame(
            $current,
            $activity->getCurrentRevision(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testReopenIsNotFoundWithoutACurrentRevision(): void
    {
        $activity = new Activity();
        $member = $this->user(8025)->member;
        $activity->setCreator($member);
        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->reopen(
            $this->user(8025),
            $activity,
        );
    }

    private function anActivityNamed(string $name): Activity
    {
        foreach ($this->entityManager->getRepository(Activity::class)->findAll() as $activity) {
            if (
                $name === $activity->getLiveRevision()?->name->getValueEN()
                || $name === $activity->getCurrentRevision()?->name->getValueEN()
            ) {
                return $activity;
            }
        }

        self::fail('The seed is expected to contain the activity "' . $name . '".');
    }

    /**
     * The live revision is dated back onto the transaction the test rolls back, so an activity that has taken place
     * does not depend on which of the seeded ones is picked.
     */
    private function moveIntoThePast(Activity $activity): void
    {
        $revision = $activity->getLiveRevision();
        self::assertNotNull($revision);

        $revision->beginTime = new DateTimeImmutable('-2 days');
        $revision->endTime = new DateTimeImmutable('-1 day');

        self::assertTrue($activity->hasPassed());
    }

    private function controller(): AdminController
    {
        return self::getContainer()->get(AdminController::class);
    }

    private function authenticate(int $lidnr = 8025): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user($lidnr),
            'main',
            ['ROLE_BOARD'],
        ));
    }

    private function pushRequestWithSession(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setSession($session);
        $request->setLocale('en');
        self::getContainer()->get('request_stack')->push($request);

        return $session;
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function anApprovedActivity(): Activity
    {
        $activity = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(
                Activity::class,
                'a',
            )
            ->join(
                'a.liveRevision',
                'lr',
            )
            ->where('a.cancelledAt IS NULL')
            ->andWhere('a.unpublishedAt IS NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Activity::class,
            $activity,
            'The seed is expected to contain an approved activity.',
        );

        return $activity;
    }

    /**
     * An approved activity that has still to take place, which is what cancelling requires.
     */
    private function anUpcomingApprovedActivity(): Activity
    {
        $activity = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(
                Activity::class,
                'a',
            )
            ->join(
                'a.liveRevision',
                'lr',
            )
            ->where('a.cancelledAt IS NULL')
            ->andWhere('a.unpublishedAt IS NULL')
            ->andWhere('lr.endTime > :now')
            ->setParameter(
                'now',
                new DateTimeImmutable(),
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Activity::class,
            $activity,
            'The seed is expected to contain an approved activity that has still to take place.',
        );

        return $activity;
    }

    private function aNeverApprovedActivity(): Activity
    {
        $activity = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(
                Activity::class,
                'a',
            )
            ->where('a.liveRevision IS NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Activity::class,
            $activity,
            'The seed is expected to contain a never-approved activity.',
        );

        return $activity;
    }
}
