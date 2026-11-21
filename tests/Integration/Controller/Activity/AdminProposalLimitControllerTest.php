<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\AdminProposalLimitController;
use App\Entity\Activity\ProposalLimit;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Decision\Organ;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;
use function array_values;

/**
 * The board's standing exceptions to how many activities a body may propose per round. The seed gives KEUR one,
 * which is both what the page shows and the row to edit and remove.
 *
 * Actions are invoked directly with the board on the token storage. A form submission is pushed onto the request
 * stack, because the stateless CSRF check reads the current request.
 */
final class AdminProposalLimitControllerTest extends DatabaseTestCase
{
    public function testThePageListsTheExceptionsAndTheUsualNumber(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $response = $this->controller()->index(new Request());

        self::assertTrue($response->isSuccessful());

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'KEUR',
            $content,
        );
        self::assertStringContainsString(
            'proposal_limit',
            $content,
        );
    }

    public function testAnExceptionCanBeRecordedForABodyThatHasNone(): void
    {
        $organ = $this->organ('BAC');
        self::assertNull($this->limitFor($organ));

        $this->authenticate();
        $request = $this->submit([
            'organ' => (string) $organ->id,
            'maxProposals' => '3',
        ]);

        $response = $this->controller()->index($request);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        $stored = $this->limitFor($organ);
        self::assertInstanceOf(
            ProposalLimit::class,
            $stored,
        );
        self::assertSame(
            3,
            $stored->maxProposals,
        );
        self::assertNotEmpty($this->flashes($request, AlertTypes::Success));
    }

    public function testANegativeNumberIsRefused(): void
    {
        $organ = $this->organ('BAC');

        $this->authenticate();
        $request = $this->submit([
            'organ' => (string) $organ->id,
            'maxProposals' => '-1',
        ]);

        $response = $this->controller()->index($request);

        self::assertNotInstanceOf(
            RedirectResponse::class,
            $response,
        );
        // An invalid submitted form is rendered as 422 rather than 200, which is what the browser receives.
        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'negative number of activities',
            (string) $response->getContent(),
        );
        self::assertNull($this->limitFor($organ));
    }

    public function testABodyHasToBePicked(): void
    {
        $this->authenticate();
        $request = $this->submit([
            'organ' => '',
            'maxProposals' => '2',
        ]);

        $response = $this->controller()->index($request);

        self::assertNotInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'Pick the body this applies to',
            (string) $response->getContent(),
        );
    }

    public function testEditingAnExceptionShowsWhatItSays(): void
    {
        $limit = $this->aSeededLimit();

        $this->authenticate();
        $this->pushRequest();

        $response = $this->controller()->edit(
            new Request(),
            $limit,
        );

        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString(
            'value="2"',
            (string) $response->getContent(),
        );
    }

    public function testAnEditedExceptionIsSaved(): void
    {
        $limit = $this->aSeededLimit();

        $this->authenticate();
        $request = $this->submit(
            ['maxProposals' => '5'],
            '/en/admin/activities/calendar/limits/0/edit',
        );

        $response = $this->controller()->edit(
            $request,
            $limit,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            5,
            $limit->maxProposals,
        );
        self::assertNotEmpty($this->flashes($request, AlertTypes::Success));
    }

    public function testAnEditThatIsNotValidComesBackWithTheForm(): void
    {
        $limit = $this->aSeededLimit();
        $id = $limit->id;
        self::assertNotNull($id);

        $this->authenticate();
        $request = $this->submit(
            ['maxProposals' => '-2'],
            '/en/admin/activities/calendar/limits/0/edit',
        );

        $response = $this->controller()->edit(
            $request,
            $limit,
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
            'negative number of activities',
            (string) $response->getContent(),
        );

        // The rejected value is bound onto the entity but never flushed.
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(ProposalLimit::class)->find($id);
        self::assertInstanceOf(
            ProposalLimit::class,
            $reloaded,
        );
        self::assertSame(
            2,
            $reloaded->maxProposals,
        );
    }

    public function testRemovingAnExceptionPutsTheBodyBackOnTheUsualNumber(): void
    {
        $limit = $this->aSeededLimit();
        $id = $limit->id;
        self::assertNotNull($id);

        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->delete($limit);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNull($this->entityManager->getRepository(ProposalLimit::class)->find($id));
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(
        array $fields,
        string $path = '/en/admin/activities/calendar/limits',
    ): Request {
        $fields['_csrf_token'] = 'csrf-token';

        $request = Request::create(
            $path,
            'POST',
            ['proposal_limit' => $fields],
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

    private function aSeededLimit(): ProposalLimit
    {
        $limit = $this->limitFor($this->organ('KEUR'));
        self::assertInstanceOf(
            ProposalLimit::class,
            $limit,
            'The seed is expected to give KEUR a standing limit.',
        );

        return $limit;
    }

    private function limitFor(Organ $organ): ?ProposalLimit
    {
        return $this->entityManager->getRepository(ProposalLimit::class)->findOneBy([
            'organ' => $organ,
        ]);
    }

    private function organ(string $abbr): Organ
    {
        $organ = $this->entityManager->getRepository(Organ::class)->findOneBy(['abbr' => $abbr]);
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        return $organ;
    }

    private function controller(): AdminProposalLimitController
    {
        return self::getContainer()->get(AdminProposalLimitController::class);
    }

    private function pushRequest(): FlashBagAwareSessionInterface
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
