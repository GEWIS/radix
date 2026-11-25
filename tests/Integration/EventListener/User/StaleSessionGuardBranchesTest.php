<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener\User;

use App\Entity\User\Enums\DeviceTypes;
use App\Entity\User\Session;
use App\Entity\User\User;
use App\EventListener\User\StaleSessionGuardListener;
use App\Repository\User\SessionRepository;
use App\Security\User\CredentialsSignature;
use App\Security\User\SessionRowSignature;
use App\Security\User\UrlSafeToken;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

use function hash;

/**
 * Every way out of the guard ends a session, and each of them is a different answer to a member asking why they were
 * signed out. The branches that are not the ordinary resume are covered here.
 */
final class StaleSessionGuardBranchesTest extends DatabaseTestCase
{
    public function testAnUnauthenticatedRequestIsLeftAlone(): void
    {
        $request = $this->requestFor(
            $this->user(8000),
            'a-series-nobody-stored',
        );

        $this->tokenStorage()->setToken(null);

        self::assertNull($this->guard($request)->getResponse());
    }

    public function testARequestWithoutAPreviousSessionIsLeftAlone(): void
    {
        $request = Request::create('/en/admin');
        $request->setSession($this->session());

        $this->tokenStorage()->setToken($this->token($this->user(8000)));

        self::assertNull($this->guard($request)->getResponse());
    }

    /**
     * A remember-me cookie is always issued at sign-in, so an authenticated request without one is anomalous: a stolen
     * PHP session cookie could otherwise self-upgrade to a persistent one.
     */
    public function testAnAuthenticatedRequestWithoutACookieIsTornDown(): void
    {
        $user = $this->user(8000);
        $phpSession = $this->session();
        $series = $this->row(
            $user,
            phpSessionId: $phpSession->getId(),
        );

        $request = $this->requestFor(
            $user,
            $series,
            $phpSession,
        );
        $request->cookies->remove('GWS_USER_REMEMBERME');

        $this->tokenStorage()->setToken($this->token($user));

        $event = $this->guard($request);

        self::assertNotNull($event->getResponse());
        self::assertNull($this->repository()->findOneBySeries($series));
    }

    public function testACookieThatNamesASeriesNobodyStoredIsTornDown(): void
    {
        $user = $this->user(8000);

        $request = $this->requestFor(
            $user,
            'a-series-nobody-stored',
        );
        $this->tokenStorage()->setToken($this->token($user));

        self::assertNotNull($this->guard($request)->getResponse());
    }

    /**
     * A cookie from one firewall presented on another's URL is refused, and the row is left where it is.
     */
    public function testACookieFromAnotherFirewallIsRefusedWithoutTouchingTheRow(): void
    {
        $user = $this->user(8000);
        $phpSession = $this->session();
        $series = $this->row(
            $user,
            firewall: 'company',
            phpSessionId: $phpSession->getId(),
        );

        $request = $this->requestFor(
            $user,
            $series,
            $phpSession,
        );
        $this->tokenStorage()->setToken($this->token($user));

        self::assertNull($this->guard($request)->getResponse());
        self::assertNotNull($this->repository()->findOneBySeries($series));
    }

    /**
     * The handler makes the same comparison, but only on a request that sends it the cookie, which a device with a live
     * PHP session never makes.
     */
    public function testASessionStampedBeforeAPasswordChangeIsTornDown(): void
    {
        $user = $this->user(8000);
        $other = $this->user(8002);
        $phpSession = $this->session();
        $series = $this->row(
            $other,
            phpSessionId: $phpSession->getId(),
        );

        $request = $this->requestFor(
            $user,
            $series,
            $phpSession,
        );
        $this->tokenStorage()->setToken($this->token($user));

        self::assertNotNull($this->guard($request)->getResponse());
        self::assertNull($this->repository()->findOneBySeries($series));
    }

    /**
     * A cookie pair replayed from a different browser is evidence enough to end the session.
     */
    public function testASessionUsedFromAnotherBrowserIsTornDown(): void
    {
        $user = $this->user(8000);
        $phpSession = $this->session();
        $series = $this->row(
            $user,
            browser: 'Chrome 124',
            phpSessionId: $phpSession->getId(),
        );

        $request = $this->requestFor(
            $user,
            $series,
            $phpSession,
        );
        $request->headers->set(
            'User-Agent',
            'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
        );
        $this->tokenStorage()->setToken($this->token($user));

        self::assertNotNull($this->guard($request)->getResponse());
        self::assertNull($this->repository()->findOneBySeries($series));
    }

    public function testASessionUsedFromAnotherOperatingSystemIsTornDown(): void
    {
        $user = $this->user(8000);
        $phpSession = $this->session();
        $series = $this->row(
            $user,
            operatingSystem: 'Windows',
            phpSessionId: $phpSession->getId(),
        );

        $request = $this->requestFor(
            $user,
            $series,
            $phpSession,
        );
        $request->headers->set(
            'User-Agent',
            'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
        );
        $this->tokenStorage()->setToken($this->token($user));

        self::assertNotNull($this->guard($request)->getResponse());
    }

    /**
     * Symfony migrates the session ID after a remember-me-resumed login, so the pointer is rebound rather than read as
     * a different device.
     */
    public function testThePointerToThePhpSessionIsRebound(): void
    {
        $user = $this->user(8000);
        $phpSession = $this->session();
        $series = $this->row(
            $user,
            phpSessionId: 'the-id-before-the-migration',
        );

        $request = $this->requestFor(
            $user,
            $series,
            $phpSession,
        );
        $this->tokenStorage()->setToken($this->token($user));

        self::assertNull($this->guard($request)->getResponse());

        $this->entityManager->clear();
        $row = $this->repository()->findOneBySeries($series);
        self::assertNotNull($row);
        self::assertSame(
            $phpSession->getId(),
            $row->phpSessionId,
        );
    }

    /**
     * "Last seen" tracks real activity rather than only the moments of token rotation, behind a throttle.
     */
    public function testAStaleLastSeenIsBumped(): void
    {
        $user = $this->user(8000);
        $phpSession = $this->session();
        $old = new DateTimeImmutable('-2 days');
        $series = $this->row(
            $user,
            lastUsedAt: $old,
            phpSessionId: $phpSession->getId(),
        );

        $request = $this->requestFor(
            $user,
            $series,
            $phpSession,
        );
        $this->tokenStorage()->setToken($this->token($user));

        self::assertNull($this->guard($request)->getResponse());

        $this->entityManager->clear();
        $row = $this->repository()->findOneBySeries($series);
        self::assertNotNull($row);
        self::assertGreaterThan(
            $old,
            $row->lastUsedAt,
        );
    }

    public function testARecentLastSeenIsNotWrittenAgain(): void
    {
        $user = $this->user(8000);
        $phpSession = $this->session();
        $recent = new DateTimeImmutable('-1 minute');
        $series = $this->row(
            $user,
            lastUsedAt: $recent,
            phpSessionId: $phpSession->getId(),
        );

        $request = $this->requestFor(
            $user,
            $series,
            $phpSession,
        );
        $this->tokenStorage()->setToken($this->token($user));

        self::assertNull($this->guard($request)->getResponse());

        $this->entityManager->clear();
        $row = $this->repository()->findOneBySeries($series);
        self::assertNotNull($row);
        self::assertSame(
            $recent->getTimestamp(),
            $row->lastUsedAt->getTimestamp(),
        );
    }

    private function guard(Request $request): RequestEvent
    {
        $requestStack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(
            RequestStack::class,
            $requestStack,
        );
        $requestStack->push($request);

        $listener = self::getContainer()->get(StaleSessionGuardListener::class);
        self::assertInstanceOf(
            StaleSessionGuardListener::class,
            $listener,
        );

        $kernel = self::$kernel;
        self::assertInstanceOf(
            HttpKernelInterface::class,
            $kernel,
        );

        $event = new RequestEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
        $listener($event);

        return $event;
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

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        );
    }

    private function row(
        User $user,
        string $firewall = 'main',
        ?string $browser = null,
        ?string $operatingSystem = null,
        ?DateTimeImmutable $lastUsedAt = null,
        string $phpSessionId = 'a-php-session-id',
    ): string {
        $credentials = self::getContainer()->get(CredentialsSignature::class);
        self::assertInstanceOf(
            CredentialsSignature::class,
            $credentials,
        );
        $rowSignature = self::getContainer()->get(SessionRowSignature::class);
        self::assertInstanceOf(
            SessionRowSignature::class,
            $rowSignature,
        );

        $now = new DateTimeImmutable();
        $series = UrlSafeToken::generate(44);

        $session = new Session();
        $session->series = $series;
        $session->hashedToken = hash(
            'sha256',
            UrlSafeToken::generate(),
        );
        $session->signaturePropertiesHash = $credentials->hash($user);
        $session->firewallName = $firewall;
        $session->userIdentifier = $user->getUserIdentifier();
        $session->createdAt = $now;
        $session->expiresAt = $now->modify('+30 days');
        $session->lastUsedAt = $lastUsedAt ?? $now;
        $session->userAgent = '';
        $session->ipAddress = '127.0.0.1';
        $session->phpSessionId = $phpSessionId;
        $session->deviceType = DeviceTypes::Unknown;
        $session->browser = $browser;
        $session->operatingSystem = $operatingSystem;
        $session->signature = $rowSignature->forRow($session);

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $series;
    }

    private function requestFor(
        User $user,
        string $series,
        ?SessionInterface $phpSession = null,
    ): Request {
        $phpSession ??= $this->session();

        $rememberMe = new RememberMeDetails(
            $user->getUserIdentifier(),
            new DateTimeImmutable('+30 days')->getTimestamp(),
            $series . ':' . UrlSafeToken::generate(),
        );

        $request = Request::create('/en/admin');
        $request->cookies->set(
            $phpSession->getName(),
            $phpSession->getId(),
        );
        $request->cookies->set(
            'GWS_USER_REMEMBERME',
            $rememberMe->toString(),
        );
        $request->setSession($phpSession);

        return $request;
    }

    private function session(): SessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->setId('a-php-session-id');

        return $session;
    }

    private function repository(): SessionRepository
    {
        $repository = self::getContainer()->get(SessionRepository::class);
        self::assertInstanceOf(
            SessionRepository::class,
            $repository,
        );

        return $repository;
    }

    private function tokenStorage(): TokenStorageInterface
    {
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(
            TokenStorageInterface::class,
            $tokenStorage,
        );

        return $tokenStorage;
    }
}
