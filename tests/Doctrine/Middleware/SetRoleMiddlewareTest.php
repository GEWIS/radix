<?php

declare(strict_types=1);

namespace App\Tests\Doctrine\Middleware;

use App\Doctrine\Middleware\SetRoleDriver;
use App\Doctrine\Middleware\SetRoleMiddleware;
use Doctrine\DBAL\Driver;
use PHPUnit\Framework\TestCase;

/**
 * Both PostgreSQL connections are wrapped, so every one of them drops to the least-privileged role its DSN names.
 */
final class SetRoleMiddlewareTest extends TestCase
{
    public function testTheDriverIsWrapped(): void
    {
        self::assertInstanceOf(
            SetRoleDriver::class,
            new SetRoleMiddleware()->wrap(self::createStub(Driver::class)),
        );
    }
}
