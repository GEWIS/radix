<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\Entity\User\Enums\SecurityEventType;
use App\EventListener\User\SecurityAuditListener;
use App\Monolog\Processor\RequestIdProcessor;
use App\Repository\User\SecurityLogRepository;
use App\Security\User\UserAgentParser;
use App\Service\User\SecurityEventLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

use function array_key_last;
use function array_map;
use function array_values;

/**
 * Firewall events are recorded here rather than in the authenticators, because the same events occur on both
 * firewalls and in several authenticators.
 */
final class SecurityAuditListenerTest extends TestCase
{
    private TestHandler $log;

    private TokenStorage $tokenStorage;

    public function testASignInIsRecorded(): void
    {
        $this->listener()->onLoginSuccess($this->success());

        self::assertSame(
            [SecurityEventType::SignInSucceeded->value],
            $this->events(),
        );
        self::assertSame(
            '8000',
            $this->last()['user'],
        );
        self::assertSame(
            'main',
            $this->last()['firewall'],
        );
        self::assertFalse($this->last()['detail']['secondFactorPending']);
    }

    /**
     * A password that was accepted followed by a failed second factor is exactly the sequence worth reading back, so
     * the first half is recorded too.
     */
    public function testASignInThatStillNeedsASecondFactorIsRecordedAsSuch(): void
    {
        $token = self::createStub(TwoFactorTokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('8000');

        $this->listener()->onLoginSuccess($this->success($token));

        self::assertTrue($this->last()['detail']['secondFactorPending']);
    }

    /**
     * Resuming a session from a remembered device is not a sign-in, and is recorded by the remember-me handler instead.
     */
    public function testARememberedDeviceIsNotASignIn(): void
    {
        $this->listener()->onLoginSuccess($this->success(
            authenticator: self::createStub(RememberMeAuthenticator::class),
        ));

        self::assertSame(
            [],
            $this->events(),
        );
    }

    public function testAThrottledSignInIsRecordedAsThrottled(): void
    {
        $this->listener()->onLoginFailure($this->failure(
            new TooManyLoginAttemptsAuthenticationException(5),
        ));

        self::assertSame(
            [SecurityEventType::SignInThrottled->value],
            $this->events(),
        );
        self::assertSame(
            'TooManyLoginAttemptsAuthenticationException',
            $this->last()['detail']['reason'],
        );
        self::assertFalse($this->last()['detail']['unknownAccount']);
    }

    /**
     * Everything the user checker refuses arrives as an account status exception: a deleted member, an expired
     * membership, a company outside its window, the site closed to all but administrators.
     */
    public function testARefusedAccountIsRecordedAsRefused(): void
    {
        $this->listener()->onLoginFailure($this->failure(
            new CustomUserMessageAccountStatusException('no'),
        ));

        self::assertSame(
            [SecurityEventType::SignInRefused->value],
            $this->events(),
        );
    }

    public function testAWrongPasswordIsRecordedAsAFailureAgainstTheAttemptedAccount(): void
    {
        $this->listener()->onLoginFailure($this->failure(
            new BadCredentialsException('wrong'),
            $this->passport('8000'),
        ));

        self::assertSame(
            [SecurityEventType::SignInFailed->value],
            $this->events(),
        );
        self::assertSame(
            '8000',
            $this->last()['user'],
        );
        self::assertFalse($this->last()['detail']['unknownAccount']);
    }

    /**
     * An address with no account is not stored, or an attacker trying a list of addresses would write that list into
     * the database.
     */
    public function testAnUnknownAccountIsNotRecordedByIdentifier(): void
    {
        $this->listener()->onLoginFailure($this->failure(
            new BadCredentialsException(
                'wrong',
                previous: new UserNotFoundException('no such account'),
            ),
            $this->passport('nobody@example.com'),
        ));

        self::assertNull($this->last()['user']);
        self::assertTrue($this->last()['detail']['unknownAccount']);
    }

    public function testAFailureWithoutAPassportNamesNoAccount(): void
    {
        $this->listener()->onLoginFailure($this->failure(new BadCredentialsException('wrong')));

        self::assertNull($this->last()['user']);
    }

    public function testASignOutIsRecordedAgainstTheAccountThatLeft(): void
    {
        $this->listener()->onLogout(new LogoutEvent(
            $this->request(),
            new UsernamePasswordToken(
                new InMemoryUser(
                    '8000',
                    null,
                ),
                'main',
            ),
        ));

        self::assertSame(
            [SecurityEventType::SignedOut->value],
            $this->events(),
        );
        self::assertSame(
            '8000',
            $this->last()['user'],
        );
        self::assertSame(
            'main',
            $this->last()['firewall'],
        );
    }

    /**
     * A logout that has no token to name has no account to file it against.
     */
    public function testASignOutWithoutATokenRecordsNothing(): void
    {
        $this->listener()->onLogout(new LogoutEvent($this->request(), null));

        self::assertSame(
            [],
            $this->events(),
        );
    }

    /**
     * Both ends are filed against the member being acted as, because it is their history the action appears in.
     */
    public function testStartingAnImpersonationIsFiledAgainstTheMember(): void
    {
        $administrator = new InMemoryUser(
            '8000',
            null,
        );
        $member = new InMemoryUser(
            '8030',
            null,
        );

        $this->listener()->onSwitchUser(new SwitchUserEvent(
            $this->request(),
            $member,
            new SwitchUserToken(
                $member,
                'main',
                [],
                new UsernamePasswordToken(
                    $administrator,
                    'main',
                ),
            ),
        ));

        self::assertSame(
            [SecurityEventType::ImpersonationStarted->value],
            $this->events(),
        );
        self::assertSame(
            '8030',
            $this->last()['user'],
        );
    }

    /**
     * The event that ends an impersonation names the administrator it returns to, not the member it is leaving, so the
     * member is read from the token that is still active.
     */
    public function testEndingAnImpersonationIsFiledAgainstTheMemberItLeaves(): void
    {
        $administrator = new InMemoryUser(
            '8000',
            null,
        );
        $member = new InMemoryUser(
            '8030',
            null,
        );

        $listener = $this->listener();

        $this->tokenStorage->setToken(new SwitchUserToken(
            $member,
            'main',
            [],
            new UsernamePasswordToken(
                $administrator,
                'main',
            ),
        ));

        $listener->onSwitchUser(new SwitchUserEvent(
            $this->request(),
            $administrator,
            new UsernamePasswordToken(
                $administrator,
                'main',
            ),
        ));

        self::assertSame(
            [SecurityEventType::ImpersonationStopped->value],
            $this->events(),
        );
        self::assertSame(
            '8030',
            $this->last()['user'],
        );
    }

    public function testEndingAnImpersonationWithoutAnActiveSwitchFallsBackToTheTarget(): void
    {
        $administrator = new InMemoryUser(
            '8000',
            null,
        );

        $this->listener()->onSwitchUser(new SwitchUserEvent(
            $this->request(),
            $administrator,
            new UsernamePasswordToken(
                $administrator,
                'main',
            ),
        ));

        self::assertSame(
            '8000',
            $this->last()['user'],
        );
    }

    public function testAnAcceptedSecondFactorIsRecorded(): void
    {
        $this->listener()->onSecondFactorAccepted(new TwoFactorAuthenticationEvent(
            $this->request(),
            new UsernamePasswordToken(
                new InMemoryUser(
                    '8000',
                    null,
                ),
                'main',
            ),
        ));

        self::assertSame(
            [SecurityEventType::MfaChallengeSucceeded->value],
            $this->events(),
        );
    }

    public function testARejectedSecondFactorIsRecorded(): void
    {
        $this->listener()->onSecondFactorRejected(new TwoFactorAuthenticationEvent(
            $this->request(),
            new UsernamePasswordToken(
                new InMemoryUser(
                    '8000',
                    null,
                ),
                'main',
            ),
        ));

        self::assertSame(
            [SecurityEventType::MfaChallengeFailed->value],
            $this->events(),
        );
    }

    private function listener(): SecurityAuditListener
    {
        $this->tokenStorage = new TokenStorage();
        $this->log = new TestHandler();

        $requestStack = new RequestStack();
        $requestStack->push($this->request());

        $firewallMap = self::createStub(FirewallMap::class);
        $firewallMap->method('getFirewallConfig')->willReturn(
            new FirewallConfig(
                'main',
                'user_checker',
            ),
        );

        return new SecurityAuditListener(
            new SecurityEventLogger(
                self::createStub(SecurityLogRepository::class),
                $requestStack,
                new UserAgentParser(),
                $this->tokenStorage,
                new RequestIdProcessor(),
                new MockClock('2026-08-31 12:00:00'),
                new Logger(
                    'security',
                    [$this->log],
                ),
            ),
            $this->tokenStorage,
            $firewallMap,
        );
    }

    private function success(
        ?TokenInterface $token = null,
        ?AuthenticatorInterface $authenticator = null,
    ): LoginSuccessEvent {
        $token ??= new UsernamePasswordToken(
            new InMemoryUser(
                '8000',
                null,
            ),
            'main',
        );

        return new LoginSuccessEvent(
            $authenticator ?? self::createStub(AuthenticatorInterface::class),
            $this->passport('8000'),
            $token,
            $this->request(),
            null,
            'main',
        );
    }

    private function failure(
        AuthenticationException $exception,
        ?Passport $passport = null,
    ): LoginFailureEvent {
        return new LoginFailureEvent(
            $exception,
            self::createStub(AuthenticatorInterface::class),
            $this->request(),
            null,
            'main',
            $passport,
        );
    }

    private function passport(string $identifier): Passport
    {
        return new Passport(
            new UserBadge(
                $identifier,
                static fn (string $id): InMemoryUser => new InMemoryUser(
                    $id,
                    null,
                ),
            ),
            new PasswordCredentials('a password'),
        );
    }

    private function request(): Request
    {
        return Request::create('https://gewis.nl/en/login');
    }

    /**
     * @return list<string>
     */
    private function events(): array
    {
        return array_values(array_map(
            static fn (LogRecord $record): string => (string) $record->context['event'],
            $this->log->getRecords(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function last(): array
    {
        $records = $this->log->getRecords();

        $key = array_key_last($records);
        self::assertNotNull($key);

        return $records[$key]->context;
    }
}
