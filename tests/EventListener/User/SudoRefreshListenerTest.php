<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\EventListener\User\SudoRefreshListener;
use App\Security\User\SudoArea;
use App\Security\User\SudoComponents;
use App\Security\User\SudoMode;
use App\Service\Application\LiveComponentAction;
use App\Tests\Support\BuildsLiveComponents;
use App\Tests\Support\BuildsSudoMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SudoRefreshListenerTest extends TestCase
{
    use BuildsLiveComponents;
    use BuildsSudoMode;

    /** How far into the grant the request arrives. */
    private const int ELAPSED_SECONDS = 600;

    public function testAWriteInTheAreaExtendsTheGrant(): void
    {
        self::assertSame(
            self::IDLE_SECONDS,
            $this->remainingAfter(Request::create(
                '/en/admin/meetings',
                'POST',
            )),
        );
    }

    public function testReadingAPageOfTheAreaDoesNotExtendTheGrant(): void
    {
        self::assertSame(
            self::IDLE_SECONDS - self::ELAPSED_SECONDS,
            $this->remainingAfter(Request::create('/en/admin/meetings')),
        );
    }

    /**
     * The inline edits of the meeting administration are applied on a re-render, which invokes no action and is a
     * GET where the props fit in the address. Editing an agenda point for half an hour ended at the prompt.
     */
    public function testAReRenderThatAppliesInlineEditsExtendsTheGrant(): void
    {
        self::assertSame(
            self::IDLE_SECONDS,
            $this->remainingAfter($this->liveComponentRequest('meeting')),
        );
    }

    public function testAReRenderThatOnlyRendersDoesNotExtendTheGrant(): void
    {
        self::assertSame(
            self::IDLE_SECONDS - self::ELAPSED_SECONDS,
            $this->remainingAfter($this->liveComponentRequest('overview')),
        );
    }

    /**
     * What remains of the grant once the listener has seen the request, {@see ELAPSED_SECONDS} after it was given.
     */
    private function remainingAfter(Request $request): int
    {
        $clock = new MockClock();
        $sudoMode = $this->sudoMode(
            $this->session(),
            $this->tokenStorage('8000'),
            clock: $clock,
        );
        $sudoMode->grant();

        $clock->sleep(self::ELAPSED_SECONDS);

        $this->listener($sudoMode)(new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        return $sudoMode->remainingSeconds();
    }

    private function liveComponentRequest(string $component): Request
    {
        $request = Request::create('/en/_components/' . $component . '/get');
        $request->attributes->set(
            '_route',
            'ux_live_component',
        );
        $request->attributes->set(
            '_live_component',
            $component,
        );

        return $request;
    }

    private function listener(SudoMode $sudoMode): SudoRefreshListener
    {
        return new SudoRefreshListener(
            $sudoMode,
            new SudoArea(self::LOCALES),
            new SudoComponents($this->components()),
            new LiveComponentAction($this->components()),
        );
    }
}
