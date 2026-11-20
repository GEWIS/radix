<?php

declare(strict_types=1);

namespace App\Service\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function crc32;
use function in_array;
use function microtime;
use function sprintf;
use function usleep;

/**
 * A named mutex serialised on the database: MariaDB's GET_LOCK while the site still reads it, PostgreSQL's
 * advisory locks once it serves from the application database. Held by the session until released (a crashed
 * request drops it when the connection goes away) and not tied to a transaction.
 */
final readonly class NamedLock
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.application_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * Takes the named lock, waiting up to the timeout. False means somebody else holds it; an error throws rather
     * than falling through unguarded, because the caller's find-then-write is the race the mutex closes.
     */
    public function acquire(
        string $name,
        int $timeoutSeconds,
    ): bool {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $granted = $this->connection->executeQuery(
                'SELECT GET_LOCK(?, ?)',
                [
                    $name,
                    $timeoutSeconds,
                ],
            )->fetchOne();

            // GET_LOCK() returns 1 when granted, 0 when the wait timed out and NULL on error.
            if (null === $granted) {
                throw new RuntimeException(sprintf(
                    'Failed to obtain the mutex "%s".',
                    $name,
                ));
            }

            return 1 === (int) $granted;
        }

        // The name hashed into a bigint (advisory lock keys are integers), tried until the timeout runs out;
        // the session-scoping and cleanup are GET_LOCK's.
        $key = crc32($name);
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $granted = $this->connection->executeQuery(
                'SELECT pg_try_advisory_lock(?)',
                [$key],
            )->fetchOne();

            if (
                in_array(
                    $granted,
                    [
                        true,
                        1,
                        't',
                    ],
                    true,
                )
            ) {
                return true;
            }

            if (null === $granted) {
                throw new RuntimeException(sprintf(
                    'Failed to obtain the mutex "%s".',
                    $name,
                ));
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function release(string $name): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->connection->executeQuery(
                'SELECT RELEASE_LOCK(?)',
                [$name],
            );

            return;
        }

        $this->connection->executeQuery(
            'SELECT pg_advisory_unlock(?)',
            [crc32($name)],
        );
    }
}
