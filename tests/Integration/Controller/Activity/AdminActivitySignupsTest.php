<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\AdminController;
use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\User\User;
use App\Repository\Activity\ExternalSignupRepository;
use App\Service\Activity\SignupManager;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_map;
use function array_values;
use function count;

/**
 * The organiser-and-board side of sign-ups: the overview page, who may still read it, and adding an external
 * subscriber by hand.
 *
 * Actions are invoked directly with the user on the token storage, as the other admin controller tests do.
 */
final class AdminActivitySignupsTest extends DatabaseTestCase
{
    public function testTheBoardSeesTheSignupsOfAPublishedActivity(): void
    {
        $activity = $this->anActivityTakingSignups();

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->signups($activity);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testThereAreNoSignupsToShowWithoutALiveRevision(): void
    {
        $activity = $this->anActivityWithoutALiveRevision();

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->signups($activity);
    }

    /**
     * An organiser keeps access while the activity is near, and loses it once it has been over for more than a week.
     */
    public function testAnOrganiserLosesTheSignupsAWeekAfterTheActivity(): void
    {
        $activity = $this->anActivityThatHasEnded();
        $this->makeOrganiser(
            $activity,
            8030,
        );

        $this->authenticateAs(8030);
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->signups($activity);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testSomebodyWhoDoesNotOrganiseTheActivityCannotReadTheSignups(): void
    {
        $activity = $this->anActivityTakingSignups();

        $this->authenticateAs(8030);
        $this->pushRequestWithSession();

        $this->expectException(AccessDeniedException::class);

        $this->controller()->signups($activity);
    }

    public function testTheAddExternalFormIsOfferedOnAnOpenList(): void
    {
        $activity = $this->anActivityTakingSignups();
        $list = $this->anOpenExternalList($activity);

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->addExternalSignup(
            $activity,
            $list,
            new Request(),
            $this->signupManager(),
            $this->externalSignups(),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testAnExternalSubscriberIsAddedConfirmed(): void
    {
        $activity = $this->anActivityTakingSignups();
        $list = $this->anOpenExternalList($activity);

        $this->authenticate();
        $request = $this->submitExternal(
            'Guido Gast',
            'guido.gast@example.org',
        );

        $response = $this->controller()->addExternalSignup(
            $activity,
            $list,
            $request,
            $this->signupManager(),
            $this->externalSignups(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/admin/activities/' . $activity->id . '/signups',
            $response->getTargetUrl(),
        );

        $signup = $this->externalSignups()->findOneByListAndEmail(
            $list,
            'guido.gast@example.org',
        );
        self::assertInstanceOf(
            ExternalSignup::class,
            $signup,
        );
        self::assertNotNull(
            $signup->verifiedAt,
            'An organiser-added external is confirmed on the spot.',
        );
        self::assertTrue($signup->addedManually);
        self::assertNotEmpty($this->flashes($request, AlertTypes::Success));
    }

    public function testAnAddressAlreadyOnTheListIsRefused(): void
    {
        $activity = $this->anActivityTakingSignups();
        $list = $this->anOpenExternalList($activity);

        $existing = new ExternalSignup();
        $existing->signupList = $list;
        $existing->setFullName('Bestaande Gast');
        $existing->setEmail('al.gast@example.org');
        $existing->verifiedAt = new DateTimeImmutable();
        $existing->drawn = true;
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $before = $this->externalSignupCount();

        $this->authenticate();
        $request = $this->submitExternal(
            'Guido Gast',
            'al.gast@example.org',
        );

        $response = $this->controller()->addExternalSignup(
            $activity,
            $list,
            $request,
            $this->signupManager(),
            $this->externalSignups(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            $before,
            $this->externalSignupCount(),
        );
        self::assertNotEmpty($this->flashes($request, AlertTypes::Warning));
    }

    public function testAMissingNameLeavesTheFormOnTheScreen(): void
    {
        $activity = $this->anActivityTakingSignups();
        $list = $this->anOpenExternalList($activity);
        $before = $this->externalSignupCount();

        $this->authenticate();
        $request = $this->submitExternal(
            '',
            'zonder.naam@example.org',
        );

        $response = $this->controller()->addExternalSignup(
            $activity,
            $list,
            $request,
            $this->signupManager(),
            $this->externalSignups(),
        );

        self::assertNotInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Enter a full name',
            (string) $response->getContent(),
        );
        self::assertSame(
            $before,
            $this->externalSignupCount(),
        );
    }

    public function testAListThatIsNotThisActivitysLiveListIsNotFound(): void
    {
        $activity = $this->anActivityTakingSignups();
        $otherList = $this->aClosedList();

        $this->authenticate();
        $this->pushRequestWithSession();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->addExternalSignup(
            $activity,
            $otherList,
            new Request(),
            $this->signupManager(),
            $this->externalSignups(),
        );
    }

    public function testAFrozenActivityTakesNoSubscribers(): void
    {
        $activity = $this->anActivityTakingSignups();
        $list = $this->anOpenExternalList($activity);
        $activity->cancel($this->user(8025)->member);
        $this->entityManager->flush();

        $this->authenticate();
        $request = $this->submitExternal(
            'Guido Gast',
            'guido.gast@example.org',
        );

        $response = $this->controller()->addExternalSignup(
            $activity,
            $list,
            $request,
            $this->signupManager(),
            $this->externalSignups(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty($this->flashes($request, AlertTypes::Warning));
    }

    public function testAClosedListTakesNoSubscribers(): void
    {
        $list = $this->aClosedList();
        $activity = $list->getActivity();

        $this->authenticate();
        $request = $this->submitExternal(
            'Guido Gast',
            'guido.gast@example.org',
        );

        $response = $this->controller()->addExternalSignup(
            $activity,
            $list,
            $request,
            $this->signupManager(),
            $this->externalSignups(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty($this->flashes($request, AlertTypes::Warning));
    }

    public function testAMembersOnlyListTakesNoExternalSubscribers(): void
    {
        $activity = $this->anActivityTakingSignups();
        $list = $this->aMembersOnlyList($activity);

        $this->authenticate();
        $request = $this->submitExternal(
            'Guido Gast',
            'guido.gast@example.org',
        );

        $response = $this->controller()->addExternalSignup(
            $activity,
            $list,
            $request,
            $this->signupManager(),
            $this->externalSignups(),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty($this->flashes($request, AlertTypes::Warning));
    }

    private function submitExternal(
        string $name,
        string $email,
    ): Request {
        $request = Request::create(
            '/en/admin/activities/0/signups/0/add-external',
            'POST',
            [
                'signup' => [
                    '_csrf_token' => 'csrf-token',
                    'fullName' => $name,
                    'email' => $email,
                ],
            ],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod('POST');

        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $request->setSession($session);
        $request->setLocale('en');
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    /**
     * @return list<string>
     */
    private function flashes(
        Request $request,
        AlertTypes $type,
    ): array {
        $session = $request->getSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        return array_map(
            static fn (mixed $flash): string => (string) $flash,
            array_values($session->getFlashBag()->get($type->value)),
        );
    }

    private function anActivityTakingSignups(): Activity
    {
        foreach ($this->entityManager->getRepository(Activity::class)->findAll() as $activity) {
            if (
                'Symposium' !== $activity->getLiveRevision()?->name->getValueEN()
                || $activity->isFrozen()
            ) {
                continue;
            }

            return $activity;
        }

        self::fail('The seed is expected to contain the upcoming symposium.');
    }

    private function anOpenExternalList(Activity $activity): SignupList
    {
        foreach ($activity->getLiveSignupLists() as $list) {
            if (
                $list->isOpen()
                && !$list->onlyGEWIS
            ) {
                return $list;
            }
        }

        self::fail('The seeded activity is expected to have an open list for externals.');
    }

    private function aMembersOnlyList(Activity $activity): SignupList
    {
        foreach ($activity->getLiveSignupLists() as $list) {
            if ($list->onlyGEWIS) {
                return $list;
            }
        }

        self::fail('The seeded activity is expected to have a members-only list.');
    }

    private function aClosedList(): SignupList
    {
        foreach ($this->entityManager->getRepository(Activity::class)->findAll() as $activity) {
            if ($activity->isFrozen()) {
                continue;
            }

            foreach ($activity->getLiveSignupLists() as $list) {
                if (!$list->isOpen()) {
                    return $list;
                }
            }
        }

        self::fail('The seed is expected to contain a closed sign-up list.');
    }

    private function anActivityWithoutALiveRevision(): Activity
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
        );

        return $activity;
    }

    private function anActivityThatHasEnded(): Activity
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
            ->andWhere('lr.endTime < :aWeekAgo')
            ->setParameter(
                'aWeekAgo',
                new DateTimeImmutable('-8 days'),
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        return $activity;
    }

    /**
     * Makes the member the activity's organiser for the access rules, without touching the live schedule.
     */
    private function makeOrganiser(
        Activity $activity,
        int $lidnr,
    ): void {
        $member = $this->user($lidnr)->member;
        $activity->setCreator($member);

        $revision = $activity->getLiveRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $revision->setAuthor($member);

        $this->entityManager->flush();
    }

    private function externalSignupCount(): int
    {
        return count($this->externalSignups()->findAll());
    }

    private function externalSignups(): ExternalSignupRepository
    {
        return self::getContainer()->get(ExternalSignupRepository::class);
    }

    private function signupManager(): SignupManager
    {
        return self::getContainer()->get(SignupManager::class);
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

        $request = new Request();
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
