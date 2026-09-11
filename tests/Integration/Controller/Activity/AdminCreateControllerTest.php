<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\AdminController;
use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Activity\SignupList;
use App\Entity\User\User;
use App\Form\Activity\ActivityFlow\ActivityData;
use App\Form\Activity\ActivityFlow\ActivityFlowType;
use App\Form\Activity\Enums\SignupListSection;
use App\Service\Activity\ActivityAdminService;
use App\Tests\Integration\DatabaseTestCase;
use SortDirection;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function count;

/**
 * The action is invoked directly with the current user set on the token storage, as the other admin controller
 * tests do: the app's session guard force-logs-out any session not backed by a managed-session row, so a synthetic
 * browser session never survives a real request.
 */
final class AdminCreateControllerTest extends DatabaseTestCase
{
    private const string RUN = 'testrun';

    public function testNothingIsSavedWhileTheActivityItselfIsBeingDescribed(): void
    {
        $before = $this->activityCount();
        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->create(
            $this->get(),
            $this->user(),
        );

        self::assertNotInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            $before,
            $this->activityCount(),
        );
    }

    public function testTheFirstStepDoesNotSaveEither(): void
    {
        $before = $this->activityCount();
        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->create(
            $this->post(
                ActivityData::STEP_GENERAL,
                $this->general(),
                $session,
            ),
            $this->user(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'create',
            $response->getTargetUrl(),
        );
        self::assertSame(
            $before,
            $this->activityCount(),
        );
    }

    public function testReachingTheSignupListsSavesTheDraftAndHandsOver(): void
    {
        $before = $this->activityCount();
        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $this->controller()->create(
            $this->post(
                ActivityData::STEP_GENERAL,
                $this->general(),
                $session,
            ),
            $this->user(),
        );
        $this->controller()->create(
            $this->get(),
            $this->user(),
        );

        $response = $this->controller()->create(
            $this->finish(
                ActivityData::STEP_DETAILS,
                $this->details(),
                $session,
            ),
            $this->user(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'flow=' . self::RUN,
            $response->getTargetUrl(),
        );
        self::assertSame(
            $before + 1,
            $this->activityCount(),
        );

        $revision = $this->newestRevision();
        self::assertNotNull($revision->getBeginTime());
        self::assertNotNull($revision->getEndTime());
        self::assertSame(
            'Test activity',
            $revision->getName()->getValueEN(),
        );
    }

    public function testEditingASavedDraftMovesBetweenSteps(): void
    {
        $this->authenticate();
        $session = $this->pushRequestWithSession();
        $activity = $this->savedDraft($session);

        $this->controller()->edit(
            $this->goto(
                ActivityData::STEP_GENERAL,
                $session,
            ),
            $this->user(),
            $activity,
        );

        $response = $this->controller()->edit(
            $this->post(
                ActivityData::STEP_GENERAL,
                $this->general(),
                $session,
            ),
            $this->user(),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );

        $page = $this->controller()->edit(
            $this->get($session),
            $this->user(),
            $activity,
        );

        self::assertStringContainsString(
            'activity_flow[' . ActivityData::STEP_DETAILS . ']',
            (string) $page->getContent(),
        );
    }

    public function testWhatAListStepChangesIsKept(): void
    {
        $this->authenticate();
        $session = $this->pushRequestWithSession();
        $activity = $this->savedDraft($session);

        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $list = self::getContainer()->get(ActivityAdminService::class)->addSignupList($revision);
        $listId = (int) $list->getId();
        $step = ActivityFlowType::listStep(
            $list,
            SignupListSection::Basics,
        );

        $this->standOn(
            $step,
            $session,
        );

        $handed = $this->controller()->edit(
            $this->post(
                $step,
                [
                    'name' => [
                        'valueEN' => 'Dinner',
                        'valueNL' => 'Diner',
                    ],
                    'openDate' => '2030-05-01T12:00',
                    'closeDate' => '2030-05-20T12:00',
                ],
                $session,
            ),
            $this->user(),
            $activity,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $handed,
        );

        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(SignupList::class)->find($listId);
        self::assertInstanceOf(
            SignupList::class,
            $saved,
        );
        self::assertSame(
            'Dinner',
            $saved->getName()->getValueEN(),
        );
        self::assertSame(
            '2030-05-01 12:00',
            $saved->getOpenDate()?->format('Y-m-d H:i'),
        );
    }

    private function standOn(
        string $step,
        SessionInterface $session,
    ): void {
        $storage = new SessionDataStorage(
            ActivityFlowType::storageKey(self::RUN),
            self::getContainer()->get('request_stack'),
        );
        $data = $storage->load();
        self::assertInstanceOf(
            ActivityData::class,
            $data,
        );

        $data->step = $step;
        $storage->save($data);
    }

    private function savedDraft(SessionInterface $session): Activity
    {
        $this->controller()->create(
            $this->post(
                ActivityData::STEP_GENERAL,
                $this->general(),
                $session,
            ),
            $this->user(),
        );
        $this->controller()->create(
            $this->get($session),
            $this->user(),
        );
        $this->controller()->create(
            $this->finish(
                ActivityData::STEP_DETAILS,
                $this->details(),
                $session,
            ),
            $this->user(),
        );

        return $this->newestRevision()->getActivity();
    }

    /**
     * @return array<string, mixed>
     */
    private function general(): array
    {
        return [
            'organId' => ActivityData::NONE,
            'companyId' => ActivityData::NONE,
            'beginTime' => '2030-06-01T18:00',
            'endTime' => '2030-06-01T22:00',
            'category' => ActivityCategories::Other->value,
            'labelIds' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function details(): array
    {
        return [
            'languageEnglish' => '1',
            'nameEN' => 'Test activity',
            'locationEN' => 'Aula',
            'costsEN' => 'Free',
            'descriptionEN' => 'A talk.',
        ];
    }

    private function get(?SessionInterface $session = null): Request
    {
        $request = new Request(query: ['flow' => self::RUN]);

        if (null !== $session) {
            $request->setSession($session);
            self::getContainer()->get('request_stack')->push($request);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(
        string $step,
        array $fields,
        SessionInterface $session,
    ): Request {
        return $this->submit(
            [
                $step => $fields,
                'next' => '',
            ],
            $session,
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function finish(
        string $step,
        array $fields,
        SessionInterface $session,
    ): Request {
        return $this->submit(
            [
                $step => $fields,
                'finish' => '',
            ],
            $session,
        );
    }

    private function goto(
        string $step,
        SessionInterface $session,
    ): Request {
        return $this->submit(
            [
                ActivityData::STEP_SIGNUP_LISTS => ['open' => ''],
                'goto' => $step,
            ],
            $session,
        );
    }

    /**
     * @param array<string, mixed> $body what is sent to the flow itself
     */
    private function submit(
        array $body,
        SessionInterface $session,
    ): Request {
        $body['_csrf_token'] = self::getContainer()->get('security.csrf.token_manager')
            ->getToken('submit')
            ->getValue();

        $request = new Request(
            query: ['flow' => self::RUN],
            request: ['activity_flow' => $body],
            // The application's CSRF check is same-origin based, which a synthetic request has to say it is.
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
        );
        $request->setMethod('POST');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function controller(): AdminController
    {
        return self::getContainer()->get(AdminController::class);
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8025);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function authenticate(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user(),
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

        $request = new Request(query: ['flow' => self::RUN]);
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $session;
    }

    private function activityCount(): int
    {
        return count($this->entityManager->getRepository(Activity::class)->findAll());
    }

    private function newestRevision(): ActivityRevision
    {
        $revision = $this->entityManager->createQueryBuilder()
            ->select('ar')
            ->from(
                ActivityRevision::class,
                'ar',
            )
            ->orderBy(
                'ar.id',
                SortDirection::Descending,
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
}
