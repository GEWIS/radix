<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Tagged for `default` alone: `SET ROLE` is spelled here the way PostgreSQL spells it, and the website's MariaDB
 * connection has no role of its own to drop to. {@see SetRoleDriver} for what a connection is dropped to.
 */
#[Autoconfigure(tags: [
    [
        'name' => 'doctrine.middleware',
        'connection' => 'default',
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
