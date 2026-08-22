<?php

declare(strict_types=1);

namespace App\State\Photo;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Photo\PhotoOfTheWeek as PhotoOfTheWeekResource;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Photo\Photo;
use App\Repository\Photo\WeeklyPhotoRepository;
use App\Service\Application\FileStorage;
use App\Service\Photo\PhotoApiUrlBuilder;
use App\Service\Photo\WeeklyPhotoService;
use DateTimeInterface;
use Override;

/**
 * @implements ProviderInterface<PhotoOfTheWeekResource>
 */
final readonly class PhotoOfTheWeekProvider implements ProviderInterface
{
    private const ImageVariant URL_VARIANT = ImageVariant::W1920;

    public function __construct(
        private WeeklyPhotoRepository $weeklyPhotoRepository,
        private WeeklyPhotoService $weeklyPhotoService,
        private FileStorage $fileStorage,
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
    ): ?PhotoOfTheWeekResource {
        $weeklyPhoto = $this->weeklyPhotoRepository->getCurrentPhotoOfTheWeek();

        if (
            null === $weeklyPhoto
            || $weeklyPhoto->isHidden()
        ) {
            return null;
        }

        $photo = $weeklyPhoto->getPhoto();

        return new PhotoOfTheWeekResource(
            week: $weeklyPhoto->getWeek()->format(DateTimeInterface::ATOM),
            photo: [
                'id' => (int) $photo->getId(),
                'dateTime' => $photo->getDateTime()->format(DateTimeInterface::ATOM),
                'artist' => $photo->getArtist(),
                'camera' => $photo->getCamera(),
                'aspectRatio' => $photo->getAspectRatio(),
                'album' => [
                    'id' => (int) $photo->getAlbum()->getId(),
                    'name' => $photo->getAlbum()->getName(),
                ],
            ],
            url: $this->publicUrl($photo),
        );
    }

    private function publicUrl(Photo $photo): ?string
    {
        $path = $this->weeklyPhotoService->publicPathFor($photo);

        return $this->fileStorage->exists($path)
            ? $this->urlBuilder->storedUrl(
                $path,
                self::URL_VARIANT,
            )
            : null;
    }
}
