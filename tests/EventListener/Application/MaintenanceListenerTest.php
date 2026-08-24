<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Application;

use App\Entity\Application\Enums\MaintenanceStatus;
use App\Entity\Application\MaintenanceWindow;
use App\EventListener\Application\MaintenanceListener;
use App\Repository\Application\MaintenanceWindowRepository;
use App\Service\Application\MaintenanceStatusProvider;
use App\Tests\Support\LiveActionsDouble;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentTemplateFinderInterface;
use Twig\Environment;

use function array_map;
use function dirname;
use function json_encode;

final class MaintenanceListenerTest extends TestCase
{
    public function testTheEnvironmentFlagServesTheMaintenancePageToEveryone(): void
    {
        $event = $this->event(Request::create('/en/'));
        $this->listener(
            $this->provider(null),
            false,
            true,
        )($event);

        self::assertSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $event->getResponse()?->getStatusCode(),
        );
    }

    public function testFullMaintenanceLeavesTheSignInFlowReachable(): void
    {
        $request = Request::create('/en/login');
        $request->attributes->set(
            '_route',
            'user_login',
        );

        $event = $this->event($request);
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::Full)),
            false,
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testWithoutAnActiveWindowTheRequestPassesThrough(): void
    {
        $event = $this->event(Request::create('/en/'));
        $this->listener(
            $this->provider(null),
            false,
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testAdminsBypassMaintenance(): void
    {
        $event = $this->event(Request::create('/en/', 'POST'));
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::Full)),
            true,
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testFullMaintenanceServesTheMaintenancePage(): void
    {
        $event = $this->event(Request::create('/en/'));
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::Full)),
            false,
        )($event);

        self::assertSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $event->getResponse()?->getStatusCode(),
        );
    }

    public function testReadOnlyLetsReadsThrough(): void
    {
        $event = $this->event(Request::create('/en/'));
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testReadOnlyBouncesAWriteBackToThePreviousPage(): void
    {
        $request = Request::create(
            '/en/',
            'POST',
        );
        $request->headers->set(
            'referer',
            'http://localhost/en/photo',
        );
        $request->setSession(new Session(new MockArraySessionStorage()));

        $event = $this->event($request);
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
        )($event);

        $response = $event->getResponse();
        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            Response::HTTP_SEE_OTHER,
            $response->getStatusCode(),
        );
        self::assertSame(
            '/en/photo',
            $response->getTargetUrl(),
        );
    }

    public function testAWriteIsSentBackToTheSiteRootWhenTheRefererIsSomebodyElse(): void
    {
        $request = Request::create(
            '/en/',
            'POST',
        );
        $request->headers->set(
            'referer',
            'https://example.org/en/photo',
        );
        $request->setSession(new Session(new MockArraySessionStorage()));

        $event = $this->event($request);
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
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
    }

    public function testReadOnlyLeavesTheSignInFlowReachable(): void
    {
        $request = Request::create(
            '/en/login',
            'POST',
        );
        $request->attributes->set(
            '_route',
            'user_login',
        );

        $event = $this->event($request);
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testReadOnlyLetsALiveComponentRenderItselfAgain(): void
    {
        $event = $this->event($this->liveComponentRequest(null));
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testReadOnlyLetsALiveActionThatOnlyPagesThrough(): void
    {
        $event = $this->event($this->liveComponentRequest('loadMore'));
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testReadOnlyRefusesALiveActionThatWrites(): void
    {
        $event = $this->event($this->liveComponentRequest('vote'));
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
        )($event);

        self::assertInstanceOf(
            RedirectResponse::class,
            $event->getResponse(),
        );
    }

    public function testReadOnlyRefusesALiveActionThatIsNotOnTheComponent(): void
    {
        $event = $this->event($this->liveComponentRequest('somethingElse'));
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
        )($event);

        self::assertInstanceOf(
            RedirectResponse::class,
            $event->getResponse(),
        );
    }

    public function testReadOnlyLetsABatchOfActionsThatOnlyPageThrough(): void
    {
        $event = $this->event($this->batchRequest([
            'loadMore',
            'loadMore',
        ]));
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testReadOnlyRefusesABatchThatHoldsAWrite(): void
    {
        $event = $this->event($this->batchRequest([
            'loadMore',
            'vote',
        ]));
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
        )($event);

        self::assertInstanceOf(
            RedirectResponse::class,
            $event->getResponse(),
        );
    }

    public function testReadOnlyRefusesABatchItCannotRead(): void
    {
        $request = $this->liveComponentRequest('_batch');
        $request->request->set(
            'data',
            'not json',
        );

        $event = $this->event($request);
        $this->listener(
            $this->provider($this->window(MaintenanceStatus::ReadOnly)),
            false,
        )($event);

        self::assertInstanceOf(
            RedirectResponse::class,
            $event->getResponse(),
        );
    }

    /**
     * @param list<string> $actions
     */
    private function batchRequest(array $actions): Request
    {
        $request = $this->liveComponentRequest('_batch');
        $request->request->set(
            'data',
            json_encode([
                'props' => [],
                'actions' => array_map(
                    static fn (string $name): array => [
                        'name' => $name,
                        'args' => [],
                    ],
                    $actions,
                ),
            ]),
        );

        return $request;
    }

    private function liveComponentRequest(?string $action): Request
    {
        $request = Request::create(
            '/en/_components/overview/' . ($action ?? 'get'),
            'POST',
        );
        $request->attributes->set(
            '_route',
            'ux_live_component',
        );
        $request->attributes->set(
            '_live_component',
            'overview',
        );

        if (null !== $action) {
            $request->attributes->set(
                '_live_action',
                $action,
            );
        }

        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function listener(
        MaintenanceStatusProvider $maintenanceStatus,
        bool $isAdmin,
        bool $environmentFlag = false,
    ): MaintenanceListener {
        $security = self::createStub(Security::class);
        $security->method('isGranted')->willReturn($isAdmin);

        return new MaintenanceListener(
            $maintenanceStatus,
            $security,
            self::createStub(TranslatorInterface::class),
            $this->components(),
            $environmentFlag,
            dirname(
                __DIR__,
                3,
            ),
        );
    }

    /**
     * A factory that knows the one component the tests ask about. {@see ComponentFactory} is final, so it is built
     * rather than stubbed; `metadataFor()` answers out of the config it is given and reaches for nothing else.
     */
    private function components(): ComponentFactory
    {
        return new ComponentFactory(
            self::createStub(ComponentTemplateFinderInterface::class),
            self::createStub(ContainerInterface::class),
            self::createStub(PropertyAccessorInterface::class),
            self::createStub(EventDispatcherInterface::class),
            [
                'overview' => [
                    'key' => 'overview',
                    'template' => 'overview.html.twig',
                    'class' => LiveActionsDouble::class,
                ],
            ],
            [],
            self::createStub(Environment::class),
        );
    }

    private function provider(?MaintenanceWindow $active): MaintenanceStatusProvider
    {
        $repository = self::createStub(MaintenanceWindowRepository::class);
        $repository->method('findActiveAt')->willReturn($active);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new MaintenanceStatusProvider(
            $repository,
            $requestStack,
        );
    }

    private function window(MaintenanceStatus $status): MaintenanceWindow
    {
        $window = new MaintenanceWindow();
        $window->setStatus($status);

        return $window;
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
