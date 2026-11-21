<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Tagged for the PostgreSQL connections, which both drop to the least-privileged role their DSNs name.
 * {@see SetRoleDriver} for what a connection is dropped to.
 */
#[Autoconfigure(tags: [
    [
        'name' => 'doctrine.middleware',
        'connection' => 'database',
    ],
    [
        'name' => 'doctrine.middleware',
        'connection' => 'application',
    ],
])]
class SetRoleMiddleware implements MiddlewareInterface
{
    #[Override]
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new SetRoleDriver($driver);
    }
}
