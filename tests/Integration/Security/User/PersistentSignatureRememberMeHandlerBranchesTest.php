<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\User;

use App\Entity\User\User;
use App\Repository\User\SessionRepository;
use App\Security\User\CredentialsSignature;
use App\Security\User\PersistentSignatureRememberMeHandler;
use App\Security\User\SessionRowSignature;
use App\Security\User\UserAgentParser;
use App\Service\User\KnownDeviceRegistry;
use App\Service\User\SecurityEventLogger;
use App\Service\User\SecurityNotifier;
use App\Tests\Integration\DatabaseTestCase;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Override;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session as HttpSession;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;

use function date_default_timezone_get;
use function explode;

/**
 * The ways a remembered device is refused, and what each of them does to the row it was presented with.
 */
final class PersistentSignatureRememberMeHandlerBranchesTest extends DatabaseTestCase
{
    private const string USER = '8001';
    private const string FIREWALL = 'main';
    private const string SECRET = 'a secret that is only ever this test\'s';

    private MockClock $clock;

    private Request $request;

    private TestHandler $log;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MockClock(
            '2026-08-31 12:00:00',
            date_default_timezone_get(),
        );

        $this->request = Request::create('https://gewis.nl/');
        $this->request->setSession(new HttpSession(new MockArraySessionStorage()));

        $this->log = new TestHandler();
    }

    public function testAnEmptySecretIsRefusedAtConstruction(): void
    {
        $this->expectException(RuntimeException::class);

        $this->handler(
            'main',
            '   ',
        );
    }

    public function testWithoutARequestNoCookieIsIssued(): void
    {
        $handler = $this->handler(requestStack: new RequestStack());

        $handler->createRememberMeCookie($this->userProvider()->loadUserByIdentifier(self::USER));

        self::assertNull($this->issuedCookie());
        self::assertTrue($this->log->hasRecordThatContains('without an active request', Level::Warning));
    }

    public function testACookieValueWithoutATokenIsRefused(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->handler()->consumeRememberMeCookie(new RememberMeDetails(
            self::USER,
            $this->clock->now()->getTimestamp() + 3600,
            'a value with no delimiter in it',
        ));
    }

    public function testASeriesNobodyStoredIsRefused(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->handler()->consumeRememberMeCookie(new RememberMeDetails(
            self::USER,
            $this->clock->now()->getTimestamp() + 3600,
            'a-series-nobody-stored:a-token',
        ));
    }

    /**
     * A cookie from one firewall presented on another's handler is refused, and the row is left where it is.
     */
    public function testACookieFromAnotherFirewallIsRefused(): void
    {
        $cookie = $this->signIn($this->handler('company'));

        try {
            $this->handler('main')->consumeRememberMeCookie($cookie);
            self::fail('A cookie from another firewall was accepted.');
        } catch (AuthenticationException) {
            // Refused, which is the whole of what should happen.
        }

        self::assertNotNull($this->repository()->findOneBySeries($this->series($cookie)));
    }

    public function testAnExpiredRowIsRefusedAndRemoved(): void
    {
        $cookie = $this->signIn();
        $series = $this->series($cookie);

        $row = $this->repository()->findOneBySeries($series);
        self::assertNotNull($row);
        $row->expiresAt = $this->clock->now()->modify('-1 day');
        $this->entityManager->flush();
        $this->entityManager->clear();

        try {
            $this->handler()->consumeRememberMeCookie($cookie);
            self::fail('An expired row was accepted.');
        } catch (AuthenticationException) {
            // Refused.
        }

        self::assertNull($this->repository()->findOneBySeries($series));
    }

    public function testARowWhoseSignatureDoesNotMatchIsRefusedAndRemoved(): void
    {
        $cookie = $this->signIn();
        $series = $this->series($cookie);

        $row = $this->repository()->findOneBySeries($series);
        self::assertNotNull($row);
        $row->signature = 'a signature this row was not written with';
        $this->entityManager->flush();
        $this->entityManager->clear();

        try {
            $this->handler()->consumeRememberMeCookie($cookie);
            self::fail('A tampered row was accepted.');
        } catch (AuthenticationException) {
            // Refused.
        }

        self::assertNull($this->repository()->findOneBySeries($series));
    }

    /**
     * A password reset has to sign the account out of the devices it is remembered on.
     */
    public function testARowStampedBeforeACredentialChangeIsRefusedAndRemoved(): void
    {
        $cookie = $this->signIn();
        $series = $this->series($cookie);

        $user = $this->userProvider()->loadUserByIdentifier(self::USER);
        self::assertInstanceOf(
            User::class,
            $user,
        );
        $user->setPassword('a hash this account was not holding when it signed in');
        $this->entityManager->flush();
        $this->entityManager->clear();

        try {
            $this->handler()->consumeRememberMeCookie($cookie);
            self::fail('A row stamped before a password change was accepted.');
        } catch (AuthenticationException) {
            // Refused.
        }

        self::assertNull($this->repository()->findOneBySeries($series));
        self::assertNull($this->issuedCookie());
    }

    public function testClearingTheCookieRemovesTheRowItNames(): void
    {
        $cookie = $this->signIn();
        $series = $this->series($cookie);

        $this->request->cookies->set(
            'GWS_USER_REMEMBERME',
            $cookie->toString(),
        );

        $this->handler()->clearRememberMeCookie();

        self::assertNull($this->repository()->findOneBySeries($series));
    }

    /**
     * A cookie from another firewall names a row this handler does not own, so the handler leaves it alone.
     */
    public function testClearingDoesNotRemoveAnotherFirewallsRow(): void
    {
        $cookie = $this->signIn($this->handler('company'));
        $series = $this->series($cookie);

        $this->request->cookies->set(
            'GWS_USER_REMEMBERME',
            $cookie->toString(),
        );

        $this->handler('main')->clearRememberMeCookie();

        self::assertNotNull($this->repository()->findOneBySeries($series));
    }

    /**
     * There is no response to clear a cookie on outside a request, which is Symfony's own refusal rather than this
     * class's.
     */
    public function testClearingWithoutARequestIsRefusedByTheFramework(): void
    {
        $this->expectException(LogicException::class);

        $this->handler(requestStack: new RequestStack())->clearRememberMeCookie();
    }

    public function testAnEmptyCookieNamesNoSeries(): void
    {
        $this->request->cookies->set(
            'GWS_USER_REMEMBERME',
            '',
        );

        self::assertNull($this->handler()->getSeriesFromCookie($this->request));
    }

    public function testACookieThatCannotBeReadNamesNoSeries(): void
    {
        $this->request->cookies->set(
            'GWS_USER_REMEMBERME',
            'not a remember-me cookie',
        );

        self::assertNull($this->handler()->getSeriesFromCookie($this->request));
    }

    /**
     * A firewall that is not one of the two interactive ones has no sign-in to announce.
     */
    public function testAFirewallThatIsNotInteractiveIssuesACookieWithoutAnnouncingIt(): void
    {
        $cookie = $this->signIn($this->handler('api'));

        self::assertNotNull($this->repository()->findOneBySeries($this->series($cookie)));
    }

    private function handler(
        string $firewall = self::FIREWALL,
        string $secret = self::SECRET,
        ?RequestStack $requestStack = null,
    ): PersistentSignatureRememberMeHandler {
        if (null === $requestStack) {
            $requestStack = new RequestStack();
            $requestStack->push($this->request);
        }

        return new PersistentSignatureRememberMeHandler(
            $this->userProvider(),
            $requestStack,
            $this->entityManager,
            $this->repository(),
            $this->userAgentParser(),
            $this->securityNotifier(),
            $this->securityEvents(),
            $this->knownDevices(),
            $this->clock,
            new CredentialsSignature($secret),
            new SessionRowSignature($secret),
            $secret,
            $firewall,
            7776000,
            'GWS_USER_REMEMBERME',
            new Logger(
                'security',
                [$this->log],
            ),
        );
    }

    private function signIn(?PersistentSignatureRememberMeHandler $handler = null): RememberMeDetails
    {
        $handler ??= $this->handler();

        $handler->createRememberMeCookie($this->userProvider()->loadUserByIdentifier(self::USER));

        $issued = $this->issuedCookie();
        self::assertNotNull($issued);

        $this->clearIssuedCookie();
        $this->entityManager->clear();

        return RememberMeDetails::fromRawCookie($issued);
    }

    private function issuedCookie(): ?string
    {
        $cookie = $this->request->attributes->get(ResponseListener::COOKIE_ATTR_NAME);

        return $cookie instanceof Cookie
            ? $cookie->getValue()
            : null;
    }

    private function clearIssuedCookie(): void
    {
        $this->request->attributes->remove(ResponseListener::COOKIE_ATTR_NAME);
    }

    private function series(RememberMeDetails $details): string
    {
        return explode(
            ':',
            $details->getValue(),
            2,
        )[0];
    }

    /**
     * @return UserProviderInterface<UserInterface>
     */
    private function userProvider(): UserProviderInterface
    {
        $provider = self::getContainer()->get('security.user.provider.concrete.user_provider');
        self::assertInstanceOf(
            UserProviderInterface::class,
            $provider,
        );

        return $provider;
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

    private function userAgentParser(): UserAgentParser
    {
        $parser = self::getContainer()->get(UserAgentParser::class);
        self::assertInstanceOf(
            UserAgentParser::class,
            $parser,
        );

        return $parser;
    }

    private function securityNotifier(): SecurityNotifier
    {
        $notifier = self::getContainer()->get(SecurityNotifier::class);
        self::assertInstanceOf(
            SecurityNotifier::class,
            $notifier,
        );

        return $notifier;
    }

    private function securityEvents(): SecurityEventLogger
    {
        $logger = self::getContainer()->get(SecurityEventLogger::class);
        self::assertInstanceOf(
            SecurityEventLogger::class,
            $logger,
        );

        return $logger;
    }

    private function knownDevices(): KnownDeviceRegistry
    {
        $registry = self::getContainer()->get(KnownDeviceRegistry::class);
        self::assertInstanceOf(
            KnownDeviceRegistry::class,
            $registry,
        );

        return $registry;
    }
}
