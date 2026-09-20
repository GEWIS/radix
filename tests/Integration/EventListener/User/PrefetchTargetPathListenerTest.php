<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener\User;

use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PrefetchTargetPathListenerTest extends DatabaseTestCase
{
    private const string PROTECTED = '/en/admin/pages';
    private const string TARGET_PATH = '_security.main.target_path';

    public function testAVisitToAProtectedPageIsWhereSigningInReturnsTo(): void
    {
        $session = $this->refused(Request::create(self::PROTECTED));

        self::assertSame(
            'http://localhost' . self::PROTECTED,
            $session->get(self::TARGET_PATH),
        );
    }

    public function testAPrefetchOfAProtectedPageIsNot(): void
    {
        $session = $this->refused(Request::create(
            self::PROTECTED,
            server: ['HTTP_X_SEC_PURPOSE' => 'prefetch'],
        ));

        self::assertNull($session->get(self::TARGET_PATH));
    }

    public function testAPrefetchDoesNotReplaceWhatAVisitSet(): void
    {
        $session = $this->refused(Request::create(self::PROTECTED));
        $this->refused(
            Request::create(
                '/en/admin/news',
                server: ['HTTP_X_SEC_PURPOSE' => 'prefetch'],
            ),
            $session,
        );

        self::assertSame(
            'http://localhost' . self::PROTECTED,
            $session->get(self::TARGET_PATH),
        );
    }

    private function refused(
        Request $request,
        ?SessionInterface $session = null,
    ): SessionInterface {
        if (null !== $session) {
            $request->cookies->set(
                $session->getName(),
                $session->getId(),
            );
        }

        $session ??= new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $kernel = self::$kernel;
        self::assertInstanceOf(
            HttpKernelInterface::class,
            $kernel,
        );

        $response = $kernel->handle(
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            true,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/en/user/login',
            $response->headers->get('Location') ?? '',
        );

        return $session;
    }
}
