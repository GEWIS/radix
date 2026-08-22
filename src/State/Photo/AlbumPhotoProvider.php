<?php

declare(strict_types=1);

namespace App\State\Photo;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Photo\Photo as PhotoResource;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Photo\MemberTag;
use App\Entity\Photo\OrganTag;
use App\Entity\Photo\Photo;
use App\Entity\Photo\Tag;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\Photo\AlbumRepository;
use App\Repository\Photo\PhotoRepository;
use App\Service\Photo\PhotoApiUrlBuilder;
use App\State\Api\CollectionPagination;
use DateTimeInterface;
use Override;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @implements ProviderInterface<PhotoResource>
 */
final readonly class AlbumPhotoProvider implements ProviderInterface
{
    private const ImageVariant URL_VARIANT = ImageVariant::W1920;

    public function __construct(
        private AlbumRepository $albumRepository,
        private PhotoRepository $photoRepository,
        private CollectionPagination $pagination,
        private PhotoApiUrlBuilder $urlBuilder,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return iterable<array-key, PhotoResource>|null
     */
    #[Override]
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): ?iterable {
        $id = $uriVariables['id'] ?? null;

        if (null === $id) {
            return null;
        }

        $album = $this->albumRepository->findPublished((int) $id);

        if (null === $album) {
            return null;
        }

        [
            $page,, $limit
        ] = $this->pagination->window(
            $operation,
            $context,
        );

        $paginator = $this->photoRepository->paginateAlbumPhotos(
            $album,
            $page,
            $limit,
        );

        $resources = [];

        foreach ($paginator->getIterator() as $photo) {
            $resources[] = $this->resource($photo);
        }

        return $this->pagination->paginator(
            $resources,
            $page,
            $limit,
            $paginator->count(),
        );
    }

    private function resource(Photo $photo): PhotoResource
    {
        return new PhotoResource(
            id: (int) $photo->getId(),
            dateTime: $photo->getDateTime()->format(DateTimeInterface::ATOM),
            artist: $photo->getArtist(),
            camera: $photo->getCamera(),
            aspectRatio: $photo->getAspectRatio(),
            url: $this->urlBuilder->photoUrl(
                $photo,
                self::URL_VARIANT,
            ),
            tags: $this->tags($photo),
        );
    }

    /**
     * @return list<array{
     *     lidnr: int,
     *     full_name: string,
     * }|array{
     *     organId: int,
     *     abbreviation: string,
     * }>
     */
    private function tags(Photo $photo): array
    {
        $tags = [];
        $includeDeleted = $this->authorizationChecker->isGranted(ApiPermissions::MembersDeleted->value);

        foreach ($photo->getTags() as $tag) {
            $described = $this->tag(
                $tag,
                $includeDeleted,
            );

            if (null === $described) {
                continue;
            }

            $tags[] = $described;
        }

        return $tags;
    }

    /**
     * @return array{
     *     lidnr: int,
     *     full_name: string,
     * }|array{
     *     organId: int,
     *     abbreviation: string,
     * }|null
     */
    private function tag(
        Tag $tag,
        bool $includeDeleted,
    ): ?array {
        if ($tag instanceof MemberTag) {
            // Naming a deleted member is what `members_deleted` gates everywhere else the API names one.
            if (
                !$includeDeleted
                && $tag->getMember()->getDeleted()
            ) {
                return null;
            }

            return [
                'lidnr' => $tag->getMember()->getLidnr(),
                'full_name' => $tag->getMember()->getFullName(),
            ];
        }

        if ($tag instanceof OrganTag) {
            return [
                'organId' => (int) $tag->getOrgan()->getId(),
                'abbreviation' => $tag->getOrgan()->getAbbr(),
            ];
        }

        return null;
    }
}
