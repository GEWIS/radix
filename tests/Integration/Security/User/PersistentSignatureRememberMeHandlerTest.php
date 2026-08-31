<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\User;

use App\Repository\User\SessionRepository;
use App\Security\User\PersistentSignatureRememberMeHandler;
use App\Security\User\UserAgentParser;
use App\Service\User\KnownDeviceRegistry;
use App\Service\User\SecurityNotifier;
use App\Tests\Integration\DatabaseTestCase;
use Override;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session as HttpSession;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\CookieTheftException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;

use function date_default_timezone_get;
use function explode;

final class PersistentSignatureRememberMeHandlerTest extends DatabaseTestCase
{
    private const string USER = '8001';
    private const string FIREWALL = 'main';

    private MockClock $clock;

    private Request $request;

    private PersistentSignatureRememberMeHandler $handler;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // A bare DATETIME is read back in PHP's own zone, so the clock has to keep it or the signature stops matching.
        $this->clock = new MockClock(
            '2026-08-31 12:00:00',
            date_default_timezone_get(),
        );

        $this->request = Request::create('https://gewis.nl/');
        $this->request->setSession(new HttpSession(new MockArraySessionStorage()));

        $requestStack = new RequestStack();
        $requestStack->push($this->request);

        $this->handler = new PersistentSignatureRememberMeHandler(
            $this->userProvider(),
            $requestStack,
            $this->entityManager,
            $this->repository(),
            $this->userAgentParser(),
            $this->securityNotifier(),
            $this->knownDevices(),
            $this->clock,
            'a secret that is only ever this test\'s',
            self::FIREWALL,
            7776000,
            'GWS_USER_REMEMBERME',
        );
    }

    public function testUsingTheCookieRotatesTheTokenAndHandsBackTheReplacement(): void
    {
        $original = $this->signIn();

        $this->handler->consumeRememberMeCookie($original);

        $issued = $this->issuedCookie();
        self::assertNotNull($issued);
        self::assertNotSame(
            $original->toString(),
            $issued,
        );
    }

    public function testASecondTabIsAcceptedWhenItPresentsTheTokenTheFirstJustReplaced(): void
    {
        $original = $this->signIn();

        $this->handler->consumeRememberMeCookie($original);
        $this->nextRequest();

        $this->handler->consumeRememberMeCookie($original);

        self::assertNull($this->issuedCookie());
        self::assertNotNull($this->repository()->findOneBySeries($this->series($original)));
    }

    /** Both read the row before either wrote, so the second loses the conditional update. */
    public function testTheRequestThatLosesTheRotationIsAcceptedRatherThanBurningTheAccount(): void
    {
        $original = $this->signIn();

        $this->handler->consumeRememberMeCookie($original);
        $this->clearIssuedCookie();

        $this->handler->consumeRememberMeCookie($original);

        self::assertNull($this->issuedCookie());
        self::assertNotNull($this->repository()->findOneBySeries($this->series($original)));
    }

    public function testTheReplacedTokenIsRefusedOnceTheGracePeriodHasPassed(): void
    {
        $original = $this->signIn();

        $this->handler->consumeRememberMeCookie($original);
        $this->nextRequest();

        $this->clock->modify('+61 seconds');

        $this->expectException(CookieTheftException::class);

        try {
            $this->handler->consumeRememberMeCookie($original);
        } finally {
            self::assertSame(
                [],
                $this->repository()->findAllByUserOnFirewall(
                    self::USER,
                    self::FIREWALL,
                ),
            );
        }
    }

    private function signIn(): RememberMeDetails
    {
        $user = $this->userProvider()->loadUserByIdentifier(self::USER);
        $this->handler->createRememberMeCookie($user);

        $issued = $this->issuedCookie();
        self::assertNotNull($issued);

        $this->nextRequest();

        return RememberMeDetails::fromRawCookie($issued);
    }

    /** The identity map has to go, or the next call reads the row as it was rather than as it is. */
    private function nextRequest(): void
    {
        $this->clearIssuedCookie();
        $this->entityManager->clear();
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
