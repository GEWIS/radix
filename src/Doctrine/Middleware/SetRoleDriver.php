<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Override;
use SensitiveParameter;

use function is_string;

/**
 * Opens every connection under the role its own DSN names, so the application runs as a least-privileged role rather
 * than as the owner of the schema it reads.
 */
class SetRoleDriver extends AbstractDriverMiddleware
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): ConnectionInterface {
        $connection = parent::connect($params);

        // `role` is not one of DBAL's own parameters. It survives because DsnParser merges every query parameter it
        // does not recognise into the connection parameters, and the PostgreSQL driver leaves the ones it cannot
        // place out of the PDO DSN it builds — the same route `charset` and `sslmode` already travel by.
        //
        // A DSN without one is left alone: whether a deployment has a role to drop to is its own decision, and
        // compose.yaml is where production is held to having one.
        if (
            isset($params['role'])
            && is_string($params['role'])
        ) {
            $connection->exec('SET ROLE ' . $connection->quote($params['role']));
        }

        return $connection;
    }
}
