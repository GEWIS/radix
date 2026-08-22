<?php

declare(strict_types=1);

namespace App\Controller\Photo;

use App\Entity\Application\Enums\ImageVariant;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\Photo\AlbumRepository;
use App\Repository\Photo\PhotoRepository;
use App\Service\Application\FilePathResolver;
use App\Service\Application\ImageVariantResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/api/photos')]
final class ApiController extends AbstractController
{
    public const string IMAGE_PATH_TEMPLATE = '/api/photos/%d/image/%s';

    public const string COVER_PATH_TEMPLATE = '/api/photos/albums/%d/cover/%s';

    private const string NOT_FOUND = 'Not Found';

    public function __construct(
        private readonly AlbumRepository $albumRepository,
        private readonly PhotoRepository $photoRepository,
        private readonly FilePathResolver $pathResolver,
        private readonly ImageVariantResponder $variantResponder,
    ) {
    }

    #[Route(
        path: '/{id}/image/{variant}',
        name: 'api_photo_image',
        requirements: [
            'id' => '\d+',
            'variant' => '[a-z0-9]+',
        ],
        methods: ['GET'],
    )]
    #[IsGranted(ApiPermissions::PhotoAlbumsR->value)]
    public function image(
        int $id,
        string $variant,
    ): Response {
        $photo = $this->photoRepository->find($id);
        $imageVariant = ImageVariant::tryFrom($variant);

        if (
            null === $photo
            || null === $imageVariant
            || !$photo->getAlbum()->isPublished()
        ) {
            throw new NotFoundHttpException(self::NOT_FOUND);
        }

        return $this->serve(
            $photo->getPath(),
            $imageVariant,
        );
    }

    #[Route(
        path: '/albums/{id}/cover/{variant}',
        name: 'api_photo_album_cover',
        requirements: [
            'id' => '\d+',
            'variant' => '[a-z0-9]+',
        ],
        methods: ['GET'],
    )]
    #[IsGranted(ApiPermissions::PhotoAlbumsR->value)]
    public function cover(
        int $id,
        string $variant,
    ): Response {
        $coverPath = $this->albumRepository->findPublished($id)?->getCoverPath();
        $imageVariant = ImageVariant::tryFrom($variant);

        if (
            null === $coverPath
            || null === $imageVariant
        ) {
            throw new NotFoundHttpException(self::NOT_FOUND);
        }

        return $this->serve(
            $coverPath,
            $imageVariant,
        );
    }

    private function serve(
        string $path,
        ImageVariant $variant,
    ): Response {
        $namespace = $this->pathResolver->namespaceForPath($path);

        if (null === $namespace) {
            throw new NotFoundHttpException(self::NOT_FOUND);
        }

        $response = $this->variantResponder->respond(
            $path,
            $variant,
            $namespace,
        );

        if (null === $response) {
            throw new NotFoundHttpException(self::NOT_FOUND);
        }

        return $response;
    }
}
