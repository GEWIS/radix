<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Application;

use App\Controller\Application\HealthController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * `/health` opens both connections on every request, so an outage turns each one into two connect timeouts occupying
 * a FrankenPHP worker thread. It serves the container's own probe and nothing else, which is what these tests check.
 */
#[CoversClass(HealthController::class)]
final class HealthControllerTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function peers(): iterable
    {
        yield 'the probe, over loopback' => [
            ['REMOTE_ADDR' => '127.0.0.1'],
            true,
        ];

        yield 'the probe, over loopback v6' => [
            ['REMOTE_ADDR' => '::1'],
            true,
        ];

        yield 'a visitor, forwarded by the proxy' => [
            ['REMOTE_ADDR' => '131.155.10.135'],
            false,
        ];

        yield 'another container on the bridge' => [
            ['REMOTE_ADDR' => '172.18.0.7'],
            false,
        ];

        // getClientIp() would use this header; REMOTE_ADDR is read precisely so that a request from outside the
        // loopback cannot claim to come from it.
        yield 'a visitor claiming to be the probe' => [
            [
                'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
            ],
            false,
        ];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('peers')]
    public function testOnlyTheContainersOwnProbeIsAnswered(
        array $server,
        bool $answered,
    ): void {
        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create(
            '/health',
            'GET',
            [],
            [],
            [],
            $server,
        ));

        if ($answered) {
            self::assertNotSame(
                Response::HTTP_NOT_FOUND,
                $response->getStatusCode(),
            );

            return;
        }

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
        );
    }

    /**
     * The entrypoint and the queue page read more than the status: a probe that answers 200 while a database is
     * unreachable would hide the outage from them.
     */
    public function testTheAnswerNamesBothDatabasesAndTheMigrationState(): void
    {
        $marker = $this->marker();
        $existing = file_exists($marker)
            ? file_get_contents($marker)
            : null;

        if (file_exists($marker)) {
            unlink($marker);
        }

        try {
            $response = $this->probe();

            self::assertSame(
                Response::HTTP_OK,
                $response->getStatusCode(),
            );
            self::assertSame(
                [
                    'healthy' => true,
                    'migrations' => true,
                    'databases' => [
                        'ledger' => true,
                        'application' => true,
                    ],
                ],
                json_decode(
                    (string) $response->getContent(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
            );
        } finally {
            if (null !== $existing) {
                file_put_contents(
                    $marker,
                    $existing,
                );
            }
        }
    }

    /**
     * The marker is written when the entrypoint came up without migrating. Both the status and the body report it,
     * so the container is restarted rather than served.
     */
    public function testAProbeThatCameUpWithoutMigratingIsUnavailable(): void
    {
        $marker = $this->marker();
        $existing = file_exists($marker)
            ? file_get_contents($marker)
            : null;

        file_put_contents(
            $marker,
            '',
        );

        try {
            $response = $this->probe();
            $payload = json_decode(
                (string) $response->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::assertSame(
                Response::HTTP_SERVICE_UNAVAILABLE,
                $response->getStatusCode(),
            );
            self::assertFalse($payload['healthy']);
            self::assertFalse($payload['migrations']);
            self::assertTrue($payload['databases']['ledger']);
            self::assertTrue($payload['databases']['application']);
        } finally {
            if (file_exists($marker)) {
                unlink($marker);
            }

            if (null !== $existing) {
                file_put_contents(
                    $marker,
                    $existing,
                );
            }
        }
    }

    /**
     * An unreachable database is reported as a fact, not as a failed probe: restarting the container does not fix an
     * external outage, and the entrypoint would spend its startup budget waiting on it.
     */
    public function testAnUnreachableDatabaseIsReportedWithoutFailingTheProbe(): void
    {
        self::bootKernel();

        $application = self::getContainer()->get('doctrine.dbal.application_connection');
        self::assertInstanceOf(
            Connection::class,
            $application,
        );

        $ledger = self::createStub(Connection::class);
        $ledger->method('executeQuery')->willThrowException(new RuntimeException('connection refused'));

        $response = (new HealthController(
            $ledger,
            $application,
            '/nonexistent/.migrations-skipped',
        ))(Request::create(
            '/health',
            'GET',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
        ));
        $payload = json_decode(
            (string) $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertTrue($payload['healthy']);
        self::assertFalse($payload['databases']['ledger']);
        self::assertTrue($payload['databases']['application']);
    }

    private function probe(): Response
    {
        return self::bootKernel()->handle(Request::create(
            '/health',
            'GET',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
        ));
    }

    private function marker(): string
    {
        return (string) self::getContainer()->getParameter('kernel.project_dir') . '/var/.migrations-skipped';
    }
}
