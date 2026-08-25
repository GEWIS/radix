<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Application;

use App\Controller\Application\ImageController;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Service\Application\FileStorage;
use App\Service\Application\ImageSigner;
use App\Service\Application\ImageVariantResponder;
use App\Service\Application\VariantGenerator;
use App\Tests\Integration\DatabaseTestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function dirname;
use function time;

/**
 * The serving security matrix, invoked directly (the codebase has no WebTestCase). Private album originals require a
 * valid day-signature and an authenticated session; public namespaces (covers) are served unsigned and immutably
 * cacheable. A miss on an existing original queues one generation message and answers 503, and only a missing
 * original is a 404. Storage is the in-memory adapter, so responses stream rather than X-Sendfile,
 * which does not affect the status/headers under test.
 */
final class ImageControllerTest extends DatabaseTestCase
{
    public function testPublicNamespaceIsServedWithoutSignatureOrSession(): void
    {
        // Company images are public (covers are now members-only, so no longer a valid "public" example here).
        $path = $this->storeSource(StorageNamespace::CompanyImage);
        $this->pregenerate($path);

        $response = $this->controller()->serve(
            new Request(),
            'w320',
            $path,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            'image/webp',
            $response->headers->get('Content-Type'),
        );
        // Symfony normalises (sorts) the Cache-Control directives, so assert them individually.
        self::assertSame(
            '31536000',
            $response->headers->getCacheControlDirective('max-age'),
        );
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
    }

    public function testPrivateNamespaceWithoutASignatureIsDenied(): void
    {
        $path = $this->storeSource(StorageNamespace::PhotoOriginal);

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller()->serve(
            new Request(),
            'w320',
            $path,
        );
    }

    public function testPrivateNamespaceWithValidSignatureAndAuthenticatedMemberIsServed(): void
    {
        $path = $this->storeSource(StorageNamespace::PhotoOriginal);
        $this->pregenerate($path);
        $this->authenticate();

        $response = $this->controller()->serve(
            $this->signedRequest(
                'w320',
                $path,
            ),
            'w320',
            $path,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            'image/webp',
            $response->headers->get('Content-Type'),
        );
        self::assertSame(
            '86400',
            $response->headers->getCacheControlDirective('max-age'),
        );
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function testPrivateNamespaceWithValidSignatureButAnonymousIsDenied(): void
    {
        $path = $this->storeSource(StorageNamespace::PhotoOriginal);

        // A valid signature is not enough without a session. The default checker requires an authenticated user.
        $this->expectException(AccessDeniedHttpException::class);
        $this->controller()->serve(
            $this->signedRequest(
                'w320',
                $path,
            ),
            'w320',
            $path,
        );
    }

    public function testTamperedSignatureIsDenied(): void
    {
        $path = $this->storeSource(StorageNamespace::PhotoOriginal);
        $this->authenticate();

        $request = new Request(['expires' => $this->tomorrow(), 'signature' => 'tampered']);

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller()->serve(
            $request,
            'w320',
            $path,
        );
    }

    public function testUnknownVariantIsNotFound(): void
    {
        $path = $this->storeSource(StorageNamespace::PhotoCover);

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->serve(
            new Request(),
            'not-a-variant',
            $path,
        );
    }

    public function testUnknownPathIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller()->serve(
            new Request(),
            'w320',
            'somewhere/unknown/x.jpg',
        );
    }

    public function testMissingOriginalIsNotFound(): void
    {
        // Nothing is queued for a file that does not exist.
        $path = 'photos/albums/ab/does-not-exist.jpg';
        $this->authenticate();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->serve(
            $this->signedRequest(
                'w320',
                $path,
            ),
            'w320',
            $path,
        );
    }

    public function testMissingVariantIsQueuedOnceAndAnswers503(): void
    {
        $path = $this->storeSource(StorageNamespace::CompanyImage);
        $this->forgetPendingMarker($path);

        $response = $this->controller()->serve(
            new Request(),
            'w320',
            $path,
        );

        self::assertSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $response->getStatusCode(),
        );
        self::assertSame(
            '60',
            $response->headers->get('Retry-After'),
        );
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertCount(
            1,
            $this->imagesTransport()->getSent(),
        );

        // A second miss inside the pending window must not queue the same variant again.
        $response = $this->controller()->serve(
            new Request(),
            'w320',
            $path,
        );

        self::assertSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $response->getStatusCode(),
        );
        self::assertCount(
            1,
            $this->imagesTransport()->getSent(),
        );
    }

    private function pregenerate(string $path): void
    {
        self::getContainer()->get(VariantGenerator::class)->generateVariant(
            $path,
            ImageVariant::W320,
            85,
            skipUpscale: false,
        );
    }

    /** The app cache is filesystem-backed under test and the path is content-addressed, so the marker outlives a run. */
    private function forgetPendingMarker(string $path): void
    {
        $pool = self::getContainer()->get('cache.app');
        self::assertInstanceOf(
            CacheItemPoolInterface::class,
            $pool,
        );
        $pool->deleteItem(ImageVariantResponder::pendingCacheKey(
            $path,
            ImageVariant::W320,
        ));
    }

    private function imagesTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.images');
        self::assertInstanceOf(
            InMemoryTransport::class,
            $transport,
        );

        return $transport;
    }

    private function controller(): ImageController
    {
        return self::getContainer()->get(ImageController::class);
    }

    private function storeSource(StorageNamespace $namespace): string
    {
        return self::getContainer()->get(FileStorage::class)->store(
            $namespace,
            dirname(
                __DIR__,
                4,
            ) . '/tests/Resources/images/gala-dinner-1.jpg',
            '1',
        )->path;
    }

    private function signedRequest(
        string $variant,
        string $path,
    ): Request {
        [
            $expires, $signature
        ] = self::getContainer()->get(ImageSigner::class)->sign(
            $variant,
            $path,
        );

        return new Request(['expires' => $expires, 'signature' => $signature]);
    }

    private function tomorrow(): int
    {
        return time() + 86400;
    }

    private function authenticate(int $lidnr = 8000): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a user for member 8000.',
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [UserRoles::Member->value],
            ),
        );
    }
}
