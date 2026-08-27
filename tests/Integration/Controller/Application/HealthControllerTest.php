<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Application;

use App\Controller\Application\HealthController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/health` opens both connections on every request, so an outage turns each one into two connect timeouts holding
 * a FrankenPHP worker thread. It answers the container's own probe and nothing else, and this is what says so.
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

        // getClientIp() would believe this header; REMOTE_ADDR is read precisely so that nothing can claim to be
        // the loopback that is not on it.
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
}
