<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use App\Security\Application\ServingAccessCheckerInterface;
use App\Service\Application\FilePathResolver;
use App\Service\Application\ImageSigner;
use App\Service\Application\ImageVariantResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a pre-generated image variant; a cache miss queues generation and answers 503.
 *
 * Private namespaces (album photos) require a valid day-signature and pass the pluggable per-namespace access check
 * (default: an authenticated session; the photos checker additionally runs the album voter for graduates). Public
 * namespaces (covers, career, organ, page images) are served unsigned and immutably cacheable. Only a missing
 * original is a 404. The response itself comes from {@see ImageVariantResponder}.
 *
 * The route is defined non-localised in `config/routes.yaml`, so image URLs carry no `/en`|`/nl` prefix.
 */
final class ImageController extends AbstractController
{
    public function __construct(
        private readonly ImageSigner $imageSigner,
        private readonly FilePathResolver $pathResolver,
        private readonly ImageVariantResponder $variantResponder,
        /** @var iterable<ServingAccessCheckerInterface> */
        #[AutowireIterator('app.serving_access_checker')]
        private readonly iterable $accessCheckers,
    ) {
    }

    public function serve(
        Request $request,
        string $variant,
        string $path,
    ): Response {
        $imageVariant = ImageVariant::tryFrom($variant);
        $namespace = $this->pathResolver->namespaceForPath($path);
        if (
            null === $imageVariant
            || null === $namespace
        ) {
            throw new NotFoundHttpException();
        }

        if ($namespace->isPrivate()) {
            $valid = $this->imageSigner->isValid(
                $variant,
                $path,
                $request->query->getInt('expires'),
                $request->query->getString('signature'),
            );
            if (!$valid) {
                throw new AccessDeniedHttpException('Invalid or expired image signature.');
            }
        }

        if (
            !$this->accessGranted(
                $path,
                $namespace,
            )
        ) {
            throw new AccessDeniedHttpException();
        }

        $response = $this->variantResponder->respond(
            $path,
            $imageVariant,
            $namespace,
        );

        if (null === $response) {
            throw new NotFoundHttpException();
        }

        return $response;
    }

    private function accessGranted(
        string $path,
        StorageNamespace $namespace,
    ): bool {
        // Checkers are iterated in descending priority; the first that governs this namespace decides.
        foreach ($this->accessCheckers as $checker) {
            if ($checker->supports($namespace)) {
                return $checker->isGranted(
                    $path,
                    $namespace,
                );
            }
        }

        return false;
    }
}
