<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use App\Message\Application\GenerateImageVariantMessage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;

use function fpassthru;
use function hash;
use function is_file;
use function sprintf;

/**
 * A web worker never encodes an image. It used to, synchronously on a miss, and a page of uncached thumbnails was
 * enough to saturate the host; a miss now queues one message and answers 503.
 */
final readonly class ImageVariantResponder
{
    /**
     * Long enough to cover a backlogged queue, short enough that a message lost with the broker costs minutes.
     * Public because `app:image:pregenerate` marks what it queues with the same key and lifetime, so a visitor who
     * lands on a page mid-backfill does not queue a second message for a variant already on the transport.
     */
    public const int PENDING_TTL = 900;

    private const int RETRY_AFTER_SECONDS = 60;

    public function __construct(
        private VariantGenerator $variantGenerator,
        private FileStorage $fileStorage,
        private MessageBusInterface $messageBus,
        private CacheItemPoolInterface $cache,
        #[Autowire('%kernel.project_dir%/data')]
        private string $storageRootDir,
    ) {
    }

    public static function pendingCacheKey(
        string $path,
        ImageVariant $variant,
    ): string {
        // Hashed because a stored path contains characters PSR-6 reserves.
        return 'image_variant_pending.' . hash(
            'sha256',
            sprintf(
                '%s|%s',
                $variant->value,
                $path,
            ),
        );
    }

    public function respond(
        string $path,
        ImageVariant $variant,
        StorageNamespace $namespace,
    ): ?Response {
        $cachePath = $this->variantGenerator->cachePath(
            $path,
            $variant,
        );

        if ($this->fileStorage->exists($cachePath)) {
            return $this->serveVariant(
                $cachePath,
                $namespace,
            );
        }

        if (!$this->fileStorage->exists($path)) {
            return null;
        }

        $this->requestGeneration(
            $path,
            $variant,
        );

        return $this->retryLater();
    }

    /** The marker is never cleaned up: its expiry is what re-opens the door after a failed generation. */
    private function requestGeneration(
        string $path,
        ImageVariant $variant,
    ): void {
        $marker = $this->cache->getItem(self::pendingCacheKey(
            $path,
            $variant,
        ));
        if ($marker->isHit()) {
            return;
        }

        // Check-then-mark, not an atomic reservation: a duplicate message is a no-op in the handler anyway.
        $marker->set(true);
        $marker->expiresAfter(self::PENDING_TTL);
        $this->cache->save($marker);

        $this->messageBus->dispatch(new GenerateImageVariantMessage(
            $path,
            $variant,
        ));
    }

    private function retryLater(): Response
    {
        $response = new Response(
            'Image variant is being generated.',
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
        $response->headers->set(
            'Content-Type',
            'text/plain',
        );
        $response->headers->set(
            'Retry-After',
            (string) self::RETRY_AFTER_SECONDS,
        );
        $response->headers->set(
            'Cache-Control',
            'no-store',
        );

        return $response;
    }

    private function serveVariant(
        string $cachePath,
        StorageNamespace $namespace,
    ): Response {
        $absolutePath = $this->storageRootDir . '/' . $cachePath;

        if (is_file($absolutePath)) {
            $response = new BinaryFileResponse($absolutePath);
        } else {
            $stream = $this->fileStorage->readStream($cachePath);
            $response = new StreamedResponse(static function () use ($stream): void {
                fpassthru($stream);
            });
        }

        $response->headers->set(
            'Content-Type',
            'image/webp',
        );

        if ($namespace->isPrivate()) {
            $response->setPrivate();
            $response->headers->set(
                'Cache-Control',
                'private, max-age=86400',
            );
        } else {
            $response->headers->set(
                'Cache-Control',
                'public, max-age=31536000, immutable',
            );
        }

        return $response;
    }
}
