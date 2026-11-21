<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\AdminController;
use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\User\User;
use App\Form\Activity\ActivityFlow\ActivityData;
use App\Form\Activity\ActivityFlow\ActivityFlowType;
use App\Service\Activity\ActivityAdminService;
use App\Service\Application\EditLockService;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function json_decode;

/**
 * The activity editor: which states it refuses to open, who may take the edit lock, and what a save does with the
 * version it started from.
 *
 * Actions are invoked directly with the user on the token storage, as the other admin controller tests do. The
 * collected form data lives in the session, so the same session is used for the opening and finishing requests.
 */
final class AdminActivityEditTest extends DatabaseTestCase
{
    private const string RUN = 'editrun';

    public function testTheAdministrationOverviewRenders(): void
    {
        $this->authenticate();
        $this->pushRequestWithSession();

        self::assertSame(
            Response::HTTP_OK,
            $this->controller()->index()->getStatusCode(),
        );
    }

    public function testOpeningTheEditorStartsARun(): void
    {
        $activity = $this->aDraftActivity();

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->edit(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'flow=',
            $response->getTargetUrl(),
        );
    }

    public function testAnActivityThatHasTakenPlaceCannotBeRevised(): void
    {
        $activity = $this->anApprovedActivity();
        $revision = $activity->getLiveRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $revision->beginTime = new DateTimeImmutable('-2 days');
        $revision->endTime = new DateTimeImmutable('-1 day');

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->edit(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringNotContainsString(
            'flow=',
            $response->getTargetUrl(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testAnActivityBeingReviewedCannotBeEdited(): void
    {
        $revision = $this->aRevisionInStatus(RevisionStatus::Submitted);
        $activity = $revision->activity;

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->edit(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testAnActivityClosedByTheBoardCannotBeRevised(): void
    {
        $activity = $this->anApprovedActivity();
        $revision = $activity->getLiveRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $revision->setStatus(RevisionStatus::Closed);

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->edit(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testACancelledActivityCannotBeRevised(): void
    {
        $activity = $this->anActivityNamed('Cancelled Gala');

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->edit(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testAnActivityLockedByAnotherEditorShowsWhoHasIt(): void
    {
        $activity = $this->anApprovedActivity();
        $this->editLockService()->acquire(
            $activity,
            $this->user(8026),
        );

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->edit(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            $this->user(8026)->member->getFullName(),
            (string) $response->getContent(),
        );
    }

    public function testAReviewerCanTakeTheLockOver(): void
    {
        $activity = $this->anApprovedActivity();
        $this->editLockService()->acquire(
            $activity,
            $this->user(8026),
        );
        $previous = $activity->getCurrentRevision();

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->edit(
            $this->plainRequest(['take' => '1']),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'flow=',
            $response->getTargetUrl(),
        );
        self::assertNotSame(
            $previous,
            $activity->getCurrentRevision(),
            'Opening a live activity is expected to spawn a draft to work in.',
        );
    }

    /**
     * A live activity that is already under way has its start locked, but stays editable in every other respect.
     */
    public function testAnOngoingActivityKeepsItsStartReadOnly(): void
    {
        $activity = $this->anApprovedActivity();
        $revision = $activity->getLiveRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $revision->beginTime = new DateTimeImmutable('-1 day');
        $revision->endTime = new DateTimeImmutable('+1 day');

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->edit(
            $this->requestWithSession(
                $session,
                new Request(query: ['flow' => self::RUN]),
            ),
            $this->user(8025),
            $activity,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testAnActivityWithoutARevisionHasNoEditor(): void
    {
        $activity = new Activity();
        $activity->setCreator($this->user(8025)->member);
        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->edit(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
        );
    }

    public function testASaveKeepsWhatWasEntered(): void
    {
        $activity = $this->aDraftActivity();
        $session = $this->openEditor($activity);

        $response = $this->controller()->edit(
            $this->finish($session),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringEndsWith(
            '/admin/activities',
            $response->getTargetUrl(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    public function testASaveWithoutTheVersionItStartedFromIsRefused(): void
    {
        $activity = $this->aDraftActivity();
        $session = $this->openEditor($activity);

        $session->remove('activity-edit-base-version-' . $activity->id);

        $response = $this->controller()->edit(
            $this->finish($session),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/edit',
            $response->getTargetUrl(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testASaveAgainstARevisionChangedElsewhereIsRefused(): void
    {
        $activity = $this->aDraftActivity();
        $session = $this->openEditor($activity);

        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $revision->costs = new ActivityLocalisedText(
            'Aangepast',
            'Changed',
        );
        $this->entityManager->flush();

        $response = $this->controller()->edit(
            $this->finish($session),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/edit',
            $response->getTargetUrl(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testASignupListCanBeAddedToADraft(): void
    {
        $activity = $this->aDraftActivity();
        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $before = $revision->getSignupLists()->count();

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->addSignupList(
            new Request(query: ['flow' => 'listrun']),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'flow=listrun',
            $response->getTargetUrl(),
        );
        self::assertCount(
            $before + 1,
            $revision->getSignupLists(),
        );
    }

    public function testASignupListCannotBeAddedToALiveActivity(): void
    {
        $activity = $this->anApprovedActivity();
        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $before = $revision->getSignupLists()->count();

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->addSignupList(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertCount(
            $before,
            $revision->getSignupLists(),
        );
    }

    public function testASignupListCannotBeAddedWhileAnotherEditorHasTheLock(): void
    {
        $activity = $this->aDraftActivity();
        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $before = $revision->getSignupLists()->count();
        $this->editLockService()->acquire(
            $activity,
            $this->user(8026),
        );

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->controller()->addSignupList(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
        );

        self::assertCount(
            $before,
            $revision->getSignupLists(),
        );
    }

    public function testSomebodyWhoDoesNotOwnTheDraftCannotAddAList(): void
    {
        $activity = $this->aDraftActivity();

        $this->authenticateAs(8030);
        $this->pushRequestWithSession();

        $this->expectException(AccessDeniedException::class);

        $this->controller()->addSignupList(
            $this->plainRequest(),
            $this->user(8030),
            $activity,
        );
    }

    public function testASignupListOfADraftCanBeRemovedAgain(): void
    {
        $activity = $this->aDraftActivity();
        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $list = self::getContainer()->get(ActivityAdminService::class)->addSignupList($revision);
        $before = $revision->getSignupLists()->count();

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->controller()->removeSignupList(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
            $list,
        );

        self::assertCount(
            $before - 1,
            $revision->getSignupLists(),
        );
    }

    /**
     * A list with sign-ups is not removed; the controller refuses and says so.
     */
    public function testAListWithSignupsCannotBeRemoved(): void
    {
        $activity = $this->aDraftActivity();
        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $list = self::getContainer()->get(ActivityAdminService::class)->addSignupList($revision);
        $before = $revision->getSignupLists()->count();

        $signup = new ExternalSignup();
        $signup->signupList = $list;
        $signup->setFullName('Guido Gast');
        $signup->setEmail('guido.gast@example.org');
        $signup->verifiedAt = new DateTimeImmutable();
        $signup->drawn = true;
        // The inverse side too: the "has anyone signed up" check reads the list's own collection.
        $list->getSignUps()->add($signup);
        $this->entityManager->persist($signup);
        $this->entityManager->flush();

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $this->controller()->removeSignupList(
            $this->plainRequest(),
            $this->user(8025),
            $activity,
            $list,
        );

        self::assertCount(
            $before,
            $revision->getSignupLists(),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testPingAndReleaseAnswerForTheEditLock(): void
    {
        $activity = $this->aDraftActivity();
        $this->editLockService()->acquire(
            $activity,
            $this->user(8025),
        );

        $this->authenticate();
        $this->pushRequestWithSession();

        $ping = $this->controller()->editPing(
            $this->user(8025),
            $activity,
        );
        self::assertTrue(
            json_decode(
                (string) $ping->getContent(),
                true,
            )['held'],
        );

        $release = $this->controller()->editRelease(
            $this->user(8025),
            $activity,
        );
        self::assertTrue(
            json_decode(
                (string) $release->getContent(),
                true,
            )['released'],
        );
    }

    private function openEditor(Activity $activity): FlashBagAwareSessionInterface
    {
        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->edit(
            $this->requestWithSession(
                $session,
                new Request(query: ['flow' => self::RUN]),
            ),
            $this->user(8025),
            $activity,
        );
        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        // The flow stores what has been collected only once a step has been handed in. The saved draft is the same
        // content, so it is put where the finishing request will look for it, on its last step.
        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $data = ActivityData::fromRevision(
            $revision,
            false,
        );
        $data->step = ActivityData::STEP_SIGNUP_LISTS;

        $storage = new SessionDataStorage(
            ActivityFlowType::storageKey(self::RUN),
            self::getContainer()->get('request_stack'),
        );
        $storage->save($data);

        return $session;
    }

    private function finish(SessionInterface $session): Request
    {
        return $this->requestWithSession(
            $session,
            new Request(
                query: ['flow' => self::RUN],
                request: [
                    'activity_flow' => [
                        ActivityData::STEP_SIGNUP_LISTS => [],
                        'finish' => '',
                        '_csrf_token' => 'csrf-token',
                    ],
                ],
                server: [
                    'REQUEST_METHOD' => 'POST',
                    'HTTP_SEC_FETCH_SITE' => 'same-origin',
                ],
            ),
        );
    }

    /**
     * A request that names its own route: the flow's first arrival redirects to it, and a bare Request has
     * no `_route_params` for that redirect to build on.
     *
     * @param array<string, string> $query
     */
    private function plainRequest(array $query = []): Request
    {
        $request = new Request(query: $query);
        $request->attributes->set(
            '_route',
            'admin/activities/edit',
        );
        $request->attributes->set(
            '_route_params',
            ['activity' => 1],
        );

        return $request;
    }

    private function requestWithSession(
        SessionInterface $session,
        Request $request,
    ): Request {
        $request->setMethod($request->request->count() > 0 ? 'POST' : 'GET');
        $request->setSession($session);
        $request->setLocale('en');
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function aDraftActivity(): Activity
    {
        $revision = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(
                ActivityRevision::class,
                'r',
            )
            ->join(
                'r.activity',
                'a',
            )
            ->where('r.status = :draft')
            ->andWhere('a.liveRevision IS NULL')
            ->andWhere('a.currentRevision = r')
            ->setParameter(
                'draft',
                RevisionStatus::Draft->value,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
            'The seed is expected to contain a never-approved draft activity.',
        );

        return $revision->activity;
    }

    private function aRevisionInStatus(RevisionStatus $status): ActivityRevision
    {
        $revision = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(
                ActivityRevision::class,
                'r',
            )
            ->where('r.status = :status')
            ->setParameter(
                'status',
                $status->value,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );

        return $revision;
    }

    /**
     * A live, still-to-come activity that can be opened for revision.
     */
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
            ->andWhere('lr.endTime > :now')
            ->andWhere('a.currentRevision = a.liveRevision')
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
            'The seed is expected to contain an upcoming approved activity.',
        );

        return $activity;
    }

    private function anActivityNamed(string $name): Activity
    {
        foreach ($this->entityManager->getRepository(Activity::class)->findAll() as $activity) {
            if ($name === $activity->getLiveRevision()?->name->getValueEN()) {
                return $activity;
            }
        }

        self::fail('The seed is expected to contain the activity "' . $name . '".');
    }

    private function editLockService(): EditLockService
    {
        return self::getContainer()->get(EditLockService::class);
    }

    private function controller(): AdminController
    {
        return self::getContainer()->get(AdminController::class);
    }

    private function pushRequestWithSession(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request(query: ['flow' => self::RUN]);
        $request->setSession($session);
        $request->setLocale('en');
        self::getContainer()->get('request_stack')->push($request);

        return $session;
    }

    private function authenticate(int $lidnr = 8025): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user($lidnr),
            'main',
            ['ROLE_BOARD'],
        ));
    }

    private function authenticateAs(int $lidnr): void
    {
        $user = $this->user($lidnr);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));
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
}
