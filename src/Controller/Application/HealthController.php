<?php

declare(strict_types=1);

namespace App\Controller\Application;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

use function file_exists;
use function is_string;

/**
 * What the container healthcheck asks, and so what decides both whether the workers may start and whether this
 * container is reported as serving.
 *
 * Only what restarting can fix is allowed to decide that. An unreachable database is not: restarting through
 * somebody else's outage achieves nothing and costs the minutes the entrypoint spends waiting. It is reported all
 * the same, because the queue page and whatever watches this address are the ones that want to know.
 */
final class HealthController extends AbstractController
{
    /**
     * The addresses `docker/app/healthcheck.php` reaches this from, and the only ones answered. Everything else
     * arrives through the proxy, so this is closed to the internet: it opens two connections per request, which
     * during an outage is two connect timeouts holding one of eight FrankenPHP worker threads for twenty seconds.
     */
    private const array PROBE = [
        '127.0.0.0/8',
        '::1',
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $ledger,
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private readonly Connection $web,
        #[Autowire('%kernel.project_dir%/var/.migrations-skipped')]
        private readonly string $migrationsSkippedMarker,
    ) {
    }

    #[Route(
        path: '/health',
        name: 'app_health',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        // The peer that opened the connection, not `getClientIp()`: that one honours X-Forwarded-For from a trusted
        // proxy, which is precisely what must not be able to speak for the loopback. A TCP source address cannot be
        // forged from outside the host.
        $peer = $request->server->get('REMOTE_ADDR');
        if (
            !is_string($peer)
            || !IpUtils::checkIp(
                $peer,
                self::PROBE,
            )
        ) {
            throw $this->createNotFoundException();
        }

        // Set by the entrypoint when it came up without migrating, and cleared again by the retry it leaves behind
        // once both databases answer and neither has a migration pending.
        $migrated = !file_exists($this->migrationsSkippedMarker);

        return new JsonResponse(
            [
                'healthy' => $migrated,
                'migrations' => $migrated,
                'databases' => [
                    'ledger' => $this->reaches($this->ledger),
                    'web' => $this->reaches($this->web),
                ],
            ],
            $migrated ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function reaches(Connection $connection): bool
    {
        try {
            $connection->executeQuery('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
