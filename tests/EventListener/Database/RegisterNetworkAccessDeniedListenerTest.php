<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Database;

use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\EventListener\Database\RegisterNetworkAccessDeniedListener;
use App\Monolog\Processor\RequestIdProcessor;
use App\Repository\User\SecurityLogRepository;
use App\Security\Database\RegisterNetworkChecker;
use App\Security\User\UserAgentParser;
use App\Service\User\SecurityEventLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function str_contains;

/**
 * A user with the role but not on the association's network sees "your account cannot do this" unless the denial is
 * explained, and the register's links are already absent from the menu.
 */
final class RegisterNetworkAccessDeniedListenerTest extends TestCase
{
    private TestHandler $log;

    public function testADenialFromOffCampusIsExplainedAndSentHome(): void
    {
        $request = $this->request('203.0.113.10');
        $event = $this->event(
            $request,
            $this->denial(UserRoles::DatabaseAdmin->value),
        );

        $this->listener(
            $request,
            $this->user([UserRoles::DatabaseAdmin->value]),
        )($event);

        $response = $event->getResponse();
        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            '/',
            $response->getTargetUrl(),
        );
        self::assertTrue($event->isPropagationStopped());
        $session = $request->getSession();
        self::assertInstanceOf(
            Session::class,
            $session,
        );
        self::assertTrue($session->getFlashBag()->has('warning'));
        self::assertTrue($this->logged('register_access_refused'));
    }

    public function testAReadOnlySecretaryIsToldTheSameWay(): void
    {
        $request = $this->request('203.0.113.10');
        $event = $this->event(
            $request,
            $this->denial(UserRoles::DatabaseReadOnly->value),
        );

        $this->listener(
            $request,
            $this->user([UserRoles::DatabaseReadOnly->value]),
        )($event);

        self::assertNotNull($event->getResponse());
    }

    public function testADenialThatIsNotAboutTheRegisterIsLeftToTheOrdinaryPage(): void
    {
        $request = $this->request('203.0.113.10');
        $event = $this->event(
            $request,
            $this->denial('ROLE_ADMIN'),
        );

        $this->listener(
            $request,
            $this->user([UserRoles::DatabaseAdmin->value]),
        )($event);

        self::assertNull($event->getResponse());
        self::assertFalse($event->isPropagationStopped());
    }

    public function testSomethingThatIsNotAnAccessDenialIsLeftAlone(): void
    {
        $request = $this->request('203.0.113.10');
        $event = $this->event(
            $request,
            new RuntimeException('not a denial'),
        );

        $this->listener(
            $request,
            $this->user([UserRoles::DatabaseAdmin->value]),
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testADenialFromTheAssociationsNetworkIsLeftToTheOrdinaryPage(): void
    {
        $request = $this->request('192.0.2.10');
        $event = $this->event(
            $request,
            $this->denial(UserRoles::DatabaseAdmin->value),
        );

        $this->listener(
            $request,
            $this->user([UserRoles::DatabaseAdmin->value]),
        )($event);

        self::assertNull($event->getResponse());
    }

    /**
     * The roles are read before the network restriction removes them: a user who never had one gets the ordinary 403.
     */
    public function testAUserWhoNeverHeldTheRoleGetsTheOrdinaryPage(): void
    {
        $request = $this->request('203.0.113.10');
        $event = $this->event(
            $request,
            $this->denial(UserRoles::DatabaseAdmin->value),
        );

        $this->listener(
            $request,
            $this->user(['ROLE_USER']),
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testAnAnonymousRequestGetsTheOrdinaryPage(): void
    {
        $request = $this->request('203.0.113.10');
        $event = $this->event(
            $request,
            $this->denial(UserRoles::DatabaseAdmin->value),
        );

        $this->listener(
            $request,
            null,
        )($event);

        self::assertNull($event->getResponse());
    }

    /**
     * The denial is recorded before the response is decided, so a user with no session to show a message in is still
     * accounted for.
     */
    public function testADenialWithoutASessionIsRecordedButNotRedirected(): void
    {
        $request = Request::create('https://gewis.nl/en/database/members');
        $request->server->set(
            'REMOTE_ADDR',
            '203.0.113.10',
        );
        $request->attributes->set(
            '_route',
            'database_member_index',
        );
        $event = $this->event(
            $request,
            $this->denial(UserRoles::DatabaseAdmin->value),
        );

        $this->listener(
            $request,
            $this->user([UserRoles::DatabaseAdmin->value]),
        )($event);

        self::assertNull($event->getResponse());
        self::assertTrue($this->logged('register_access_refused'));
    }

    private function logged(string $message): bool
    {
        foreach ($this->log->getRecords() as $record) {
            if (
                str_contains(
                    (string) $record->message,
                    $message,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function listener(
        Request $request,
        ?User $user,
    ): RegisterNetworkAccessDeniedListener {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $tokenStorage = new TokenStorage();
        if (null !== $user) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/');

        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->log = new TestHandler();

        return new RegisterNetworkAccessDeniedListener(
            new RegisterNetworkChecker(
                $requestStack,
                ['192.0.2.0/24'],
            ),
            new SecurityEventLogger(
                self::createStub(SecurityLogRepository::class),
                $requestStack,
                new UserAgentParser(),
                $tokenStorage,
                new RequestIdProcessor(),
                new MockClock('2026-08-31 12:00:00'),
                new Logger(
                    'security',
                    [$this->log],
                ),
            ),
            $tokenStorage,
            $urlGenerator,
            $translator,
        );
    }

    private function request(string $ip): Request
    {
        $request = Request::create('https://gewis.nl/en/database/members');
        $request->server->set(
            'REMOTE_ADDR',
            $ip,
        );
        $request->attributes->set(
            '_route',
            'database_member_index',
        );
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function event(
        Request $request,
        Throwable $throwable,
    ): ExceptionEvent {
        return new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }

    private function denial(string $attribute): AccessDeniedException
    {
        $denial = new AccessDeniedException();
        $denial->setAttributes([$attribute]);

        return $denial;
    }

    /**
     * @param string[] $roles
     */
    private function user(array $roles): User
    {
        $user = self::createStub(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('getUserIdentifier')->willReturn('8000');

        return $user;
    }
}
