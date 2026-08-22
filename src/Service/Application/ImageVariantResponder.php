<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function fpassthru;
use function is_file;

final readonly class ImageVariantResponder
{
    private const int FALLBACK_QUALITY = 85;

    public function __construct(
        private FilePathResolver $pathResolver,
        private VariantGenerator $variantGenerator,
        private FileStorage $fileStorage,
        #[Autowire('%kernel.project_dir%/data')]
        private string $storageRootDir,
    ) {
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

        if (!$this->fileStorage->exists($cachePath)) {
            $quality = $this->pathResolver->profileForPath(
                $path,
                $variant,
            )?->webpQuality() ?? self::FALLBACK_QUALITY;

            if (
                !$this->variantGenerator->generateVariant(
                    $path,
                    $variant,
                    $quality,
                    skipUpscale: false,
                )
            ) {
                return null;
            }
        }

        return $this->serveVariant(
            $cachePath,
            $namespace,
        );
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
