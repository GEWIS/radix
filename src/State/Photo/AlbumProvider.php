<?php

declare(strict_types=1);

namespace App\State\Photo;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Photo\Album as AlbumResource;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Photo\Album as PhotoAlbum;
use App\Repository\Photo\AlbumRepository;
use App\Service\Photo\PhotoApiUrlBuilder;
use App\State\Api\CollectionPagination;
use DateTimeInterface;
use Override;

/**
 * @implements ProviderInterface<AlbumResource>
 */
final readonly class AlbumProvider implements ProviderInterface
{
    private const ImageVariant COVER_VARIANT = ImageVariant::Cover;

    public function __construct(
        private AlbumRepository $albumRepository,
        private CollectionPagination $pagination,
        private PhotoApiUrlBuilder $urlBuilder,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    #[Override]
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): object|array|null {
        return match ($operation->getName()) {
            AlbumResource::OPERATION_COLLECTION => $this->collection(
                $operation,
                $context,
            ),
            default => $this->one($uriVariables),
        };
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return iterable<array-key, AlbumResource>
     */
    private function collection(
        Operation $operation,
        array $context,
    ): iterable {
        [
            $page,, $limit
        ] = $this->pagination->window(
            $operation,
            $context,
        );

        $paginator = $this->albumRepository->paginatePublished(
            $page,
            $limit,
        );

        $resources = [];

        foreach ($paginator->getIterator() as $album) {
            $resources[] = $this->resource($album);
        }

        return $this->pagination->paginator(
            $resources,
            $page,
            $limit,
            $paginator->count(),
        );
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function one(array $uriVariables): ?AlbumResource
    {
        $id = $uriVariables['id'] ?? null;

        if (null === $id) {
            return null;
        }

        $album = $this->albumRepository->findPublished((int) $id);

        if (null === $album) {
            return null;
        }

        $children = [];

        foreach ($this->albumRepository->findPublishedChildren($album) as $child) {
            $children[] = $this->summary($child);
        }

        return $this->resource(
            $album,
            $children,
            $this->urlBuilder->albumPhotosUrl((int) $album->getId()),
        );
    }

    /**
     * @param array<array-key, array<string, mixed>>|null $children
     * @phpstan-param list<array{
     *     id: int,
     *     name: string,
     *     startDateTime: string|null,
     *     endDateTime: string|null,
     *     photoCount: int,
     *     albumCount: int,
     *     coverUrl: string|null,
     * }>|null $children
     */
    private function resource(
        PhotoAlbum $album,
        ?array $children = null,
        ?string $photosUrl = null,
    ): AlbumResource {
        return new AlbumResource(
            id: (int) $album->getId(),
            name: $album->getName(),
            startDateTime: $album->getStartDateTime()?->format(DateTimeInterface::ATOM),
            endDateTime: $album->getEndDateTime()?->format(DateTimeInterface::ATOM),
            parent: $album->getParent()?->getId(),
            photoCount: $album->getPhotoCount(false),
            albumCount: $album->getPublishedAlbumCount(),
            coverUrl: $this->coverUrl($album),
            children: $children,
            photosUrl: $photosUrl,
        );
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     startDateTime: string|null,
     *     endDateTime: string|null,
     *     photoCount: int,
     *     albumCount: int,
     *     coverUrl: string|null,
     * }
     */
    private function summary(PhotoAlbum $album): array
    {
        return [
            'id' => (int) $album->getId(),
            'name' => $album->getName(),
            'startDateTime' => $album->getStartDateTime()?->format(DateTimeInterface::ATOM),
            'endDateTime' => $album->getEndDateTime()?->format(DateTimeInterface::ATOM),
            'photoCount' => $album->getPhotoCount(false),
            'albumCount' => $album->getPublishedAlbumCount(),
            'coverUrl' => $this->coverUrl($album),
        ];
    }

    private function coverUrl(PhotoAlbum $album): ?string
    {
        return null === $album->getCoverPath()
            ? null
            : $this->urlBuilder->albumCoverUrl(
                (int) $album->getId(),
                self::COVER_VARIANT,
            );
    }
}
