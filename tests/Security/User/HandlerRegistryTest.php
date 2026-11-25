<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\HandlerRegistry;
use App\Security\User\PersistentSignatureRememberMeHandler;
use PHPUnit\Framework\TestCase;

/**
 * The map is immutable at runtime, and a firewall without a handler is answered with null rather than an error.
 */
final class HandlerRegistryTest extends TestCase
{
    public function testTheHandlerOfAFirewallIsHandedBack(): void
    {
        $handler = self::createStub(PersistentSignatureRememberMeHandler::class);

        self::assertSame(
            $handler,
            new HandlerRegistry(['main' => $handler])->get('main'),
        );
    }

    public function testAFirewallWithoutAHandlerIsNull(): void
    {
        self::assertNull(new HandlerRegistry([])->get('main'));
    }
}
