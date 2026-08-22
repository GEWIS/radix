<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\ApiResource\Photo\Photo as PhotoResource;
use App\Controller\Photo\ApiController;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Photo\Photo;
use App\Service\Application\ImageUrlBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function sprintf;

final readonly class PhotoApiUrlBuilder
{
    public function __construct(
        private ImageUrlBuilder $imageUrlBuilder,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
    ) {
    }

    public function photoUrl(
        Photo $photo,
        ImageVariant $variant,
    ): string {
        return $this->absolute(sprintf(
            ApiController::IMAGE_PATH_TEMPLATE,
            (int) $photo->getId(),
            $variant->value,
        ));
    }

    public function albumCoverUrl(
        int $albumId,
        ImageVariant $variant,
    ): string {
        return $this->absolute(sprintf(
            ApiController::COVER_PATH_TEMPLATE,
            $albumId,
            $variant->value,
        ));
    }

    public function storedUrl(
        string $path,
        ImageVariant $variant,
    ): string {
        return $this->absolute($this->imageUrlBuilder->url(
            $path,
            $variant,
        ));
    }

    public function albumPhotosUrl(int $albumId): string
    {
        return $this->urlGenerator->generate(
            PhotoResource::OPERATION_ALBUM_COLLECTION,
            ['id' => $albumId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function absolute(string $url): string
    {
        return ($this->requestStack->getCurrentRequest()?->getSchemeAndHttpHost() ?? '') . $url;
    }
}
