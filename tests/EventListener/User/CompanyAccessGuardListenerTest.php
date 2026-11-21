<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\Entity\Career\Company;
use App\Entity\User\CompanyUser;
use App\EventListener\User\CompanyAccessGuardListener;
use App\Monolog\Processor\RequestIdProcessor;
use App\Repository\Career\CompanyPackageRepository;
use App\Repository\User\SecurityLogRepository;
use App\Security\User\SudoMode;
use App\Security\User\UserAgentParser;
use App\Service\User\CompanyUserAccessPolicy;
use App\Service\User\SecurityEventLogger;
use App\Tests\Support\BuildsSudoMode;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Translation\TranslatorInterface;

use function str_contains;

/**
 * A representative whose company contract lapsed while signed in is signed out on the next request, because the
 * session records no contract.
 */
final class CompanyAccessGuardListenerTest extends TestCase
{
    use BuildsSudoMode;

    private TestHandler $log;

    private TokenStorage $tokenStorage;

    private SudoMode $sudoMode;

    public function testASessionThatMayNoLongerBeThereIsEnded(): void
    {
        $request = $this->request();
        $event = $this->event($request);

        $listener = $this->listener(
            $request,
            $this->companyUser(),
            allowed: false,
        );
        $this->sudoMode->grant();

        $listener($event);

        self::assertFalse($this->sudoMode->isActive());

        $response = $event->getResponse();
        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            '/en/company/login',
            $response->getTargetUrl(),
        );
        self::assertNull($this->tokenStorage->getToken());
        $session = $request->getSession();
        self::assertInstanceOf(
            Session::class,
            $session,
        );
        self::assertTrue($session->getFlashBag()->has('warning'));
        self::assertTrue($this->logged('company_access_revoked'));
    }

    public function testASessionThatMayStillBeThereIsLeftAlone(): void
    {
        $request = $this->request();
        $event = $this->event($request);

        $this->listener(
            $request,
            $this->companyUser(),
            allowed: true,
        )($event);

        self::assertNull($event->getResponse());
        self::assertNotNull($this->tokenStorage->getToken());
    }

    public function testAnotherFirewallIsLeftAlone(): void
    {
        $request = $this->request();
        $event = $this->event($request);

        $this->listener(
            $request,
            $this->companyUser(),
            allowed: false,
            firewall: 'main',
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testASubRequestIsLeftAlone(): void
    {
        $request = $this->request();
        $event = $this->event(
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->listener(
            $request,
            $this->companyUser(),
            allowed: false,
        )($event);

        self::assertNull($event->getResponse());
    }

    /**
     * The firewall is shared with the website's own users, who are not representatives and have no contract.
     */
    public function testAMemberOnTheCompanyFirewallIsLeftAlone(): void
    {
        $request = $this->request();
        $event = $this->event($request);

        $this->listener(
            $request,
            null,
            allowed: false,
        )($event);

        self::assertNull($event->getResponse());
    }

    private function listener(
        Request $request,
        ?CompanyUser $user,
        bool $allowed,
        string $firewall = 'company',
    ): CompanyAccessGuardListener {
        $this->tokenStorage = new TokenStorage();
        $this->tokenStorage->setToken(new UsernamePasswordToken(
            $user ?? new InMemoryUser(
                '8000',
                null,
            ),
            $firewall,
        ));

        $container = self::createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $id): object => 'security.token_storage' === $id
                ? $this->tokenStorage
                : self::createStub(stdClass::class),
        );

        $firewallMap = self::createStub(FirewallMap::class);
        $firewallMap->method('getFirewallConfig')->willReturn(
            new FirewallConfig(
                $firewall,
                'company_user_checker',
            ),
        );

        $packages = self::createStub(CompanyPackageRepository::class);
        $packages->method('hasNonExpiredPackage')->willReturn($allowed);

        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/en/company/login');

        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->sudoMode = $this->sudoMode(
            $request->getSession(),
            $this->tokenStorage,
            $firewall,
        );

        $requestStack = new RequestStack();
        $requestStack->push($request);
        $this->log = new TestHandler();

        return new CompanyAccessGuardListener(
            new CompanyUserAccessPolicy($packages),
            new Security($container),
            $this->tokenStorage,
            $urlGenerator,
            $translator,
            $firewallMap,
            $this->sudoMode,
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
        );
    }

    private function companyUser(): CompanyUser
    {
        $user = self::createStub(CompanyUser::class);
        $user->company = self::createStub(Company::class);
        $user->method('isDisabled')->willReturn(false);
        $user->method('getUserIdentifier')->willReturn('company@example.com');

        return $user;
    }

    private function request(): Request
    {
        $request = Request::create('https://gewis.nl/en/company/vacancies');
        $session = new Session(new MockArraySessionStorage());
        $session->setId('a-session-id');
        $request->setSession($session);

        return $request;
    }

    private function event(
        Request $request,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): RequestEvent {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            $type,
        );
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
}
