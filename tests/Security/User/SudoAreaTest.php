<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\SudoArea;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The addresses behind sudo, stated once because the listener that refuses a request and the one that extends a grant
 * both need the same list.
 */
final class SudoAreaTest extends TestCase
{
    #[DataProvider('coveredPaths')]
    public function testAnAreaIsCovered(string $path): void
    {
        self::assertTrue($this->area()->coversPath($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function coveredPaths(): iterable
    {
        yield 'the administration' => ['/en/admin'];
        yield 'a page under the administration' => ['/en/admin/pages/create'];
        yield 'the settings' => ['/nl/user/settings'];
        yield 'the security settings' => ['/en/user/security'];
        yield 'the company security settings' => ['/en/company/security'];
    }

    #[DataProvider('uncoveredPaths')]
    public function testEverythingElseIsNotCovered(string $path): void
    {
        self::assertFalse($this->area()->coversPath($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uncoveredPaths(): iterable
    {
        yield 'signing in' => ['/en/user/login'];
        yield 'confirming sudo' => ['/en/user/sudo'];
        yield 'the cosmetics every page fetches' => ['/en/user/cosmetics'];
        yield 'a live component' => ['/en/_components'];
        yield 'the front page' => ['/en'];
        yield 'an unknown locale' => ['/de/admin'];
        yield 'a path that only starts like one' => ['/en/administrator'];
    }

    public function testALiveComponentRequestIsCoveredByThePathItIsServedUnder(): void
    {
        $request = Request::create('/en/admin/pages');

        self::assertTrue($this->area()->covers($request));
    }

    #[DataProvider('keepalives')]
    public function testAnEditLockPingIsAKeepalive(string $route): void
    {
        $request = Request::create(
            '/en/admin/pages/1/edit_ping',
            'POST',
        );
        $request->attributes->set(
            '_route',
            $route,
        );

        self::assertTrue($this->area()->isKeepalive($request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keepalives(): iterable
    {
        yield 'the heartbeat' => ['admin/pages/edit_ping'];
        yield 'the release' => ['admin/pages/edit_release'];
    }

    public function testAnotherRouteIsNotAKeepalive(): void
    {
        $request = Request::create(
            '/en/admin/pages/1/edit',
            'POST',
        );
        $request->attributes->set(
            '_route',
            'admin/pages/edit',
        );

        self::assertFalse($this->area()->isKeepalive($request));
    }

    public function testARequestWithoutARouteIsNotAKeepalive(): void
    {
        self::assertFalse($this->area()->isKeepalive(Request::create('/en/admin')));
    }

    private function area(): SudoArea
    {
        return new SudoArea('en|nl');
    }
}
