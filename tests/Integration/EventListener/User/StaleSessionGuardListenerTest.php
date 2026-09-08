<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener\User;

use App\Entity\User\Enums\DeviceTypes;
use App\Entity\User\Session;
use App\Entity\User\User;
use App\EventListener\User\StaleSessionGuardListener;
use App\Security\User\CredentialsSignature;
use App\Security\User\SessionRowSignature;
use App\Security\User\UrlSafeToken;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

use function hash;

/**
 * The row of a managed session is stamped for the account that opened it. While an administrator is impersonating
 * somebody, the account on the token is the one being looked at instead, and comparing that account's credentials
 * against the administrator's row is a mismatch every time. That tore the session down on the first impersonated
 * request that reached the guard, which is every one of them now that a grant carries into an impersonation.
 */
final class StaleSessionGuardListenerTest extends DatabaseTestCase
{
    public function testAnImpersonatedRequestIsNotTornDown(): void
    {
        $administrator = $this->user(8000);
        $target = $this->user(8001);

        $request = $this->requestFor(
            $administrator,
            $this->row($administrator),
        );

        $this->tokenStorage()->setToken(new SwitchUserToken(
            $target,
            'main',
            $target->getRoles(),
            new UsernamePasswordToken(
                $administrator,
                'main',
                $administrator->getRoles(),
            ),
        ));

        self::assertNull($this->guard($request)->getResponse());
    }

    public function testASessionStampedForSomebodyElseIsStillTornDown(): void
    {
        $administrator = $this->user(8000);
        $other = $this->user(8002);

        $request = $this->requestFor(
            $administrator,
            $this->row($administrator),
        );

        // Not an impersonation: a token that simply is not the account the row was stamped for.
        $this->tokenStorage()->setToken(new UsernamePasswordToken(
            $other,
            'main',
            $other->getRoles(),
        ));

        self::assertNotNull($this->guard($request)->getResponse());
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

    /** The managed session row of a device the given account signed in from, with its series returned. */
    private function row(User $user): string
    {
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
        $session->setSeries($series);
        $session->setHashedToken(hash(
            'sha256',
            UrlSafeToken::generate(),
        ));
        $session->setSignaturePropertiesHash($credentials->hash($user));
        $session->setFirewallName('main');
        $session->setUserIdentifier($user->getUserIdentifier());
        $session->setCreatedAt($now);
        $session->setExpiresAt($now->modify('+30 days'));
        // Inside the throttle, so the guard neither writes nor refreshes device recognition.
        $session->setLastUsedAt($now);
        $session->setUserAgent('');
        $session->setIpAddress('127.0.0.1');
        $session->setPhpSessionId('a-php-session-id');
        $session->setDeviceType(DeviceTypes::Unknown);
        $session->setBrowser(null);
        $session->setOperatingSystem(null);
        $session->setSignature($rowSignature->forRow($session));

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $series;
    }

    private function requestFor(
        User $user,
        string $series,
    ): Request {
        $phpSession = self::getContainer()->get('session.factory')->createSession();
        $phpSession->setId('a-php-session-id');

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
