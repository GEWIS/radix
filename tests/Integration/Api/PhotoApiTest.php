<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\ApiResource\Photo\Album as AlbumResource;
use App\ApiResource\Photo\Photo as PhotoResource;
use App\ApiResource\Photo\PhotoOfTheWeek as PhotoOfTheWeekResource;
use App\Controller\Photo\ApiController;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Photo\Album as AlbumEntity;
use App\Entity\Photo\MemberTag;
use App\Entity\Photo\Photo as PhotoEntity;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\Photo\WeeklyPhotoRepository;
use App\Service\Application\FileStorage;
use App\Service\Application\VariantGenerator;
use App\Service\Photo\WeeklyPhotoService;
use App\State\Photo\AlbumPhotoProvider;
use App\State\Photo\AlbumProvider;
use App\State\Photo\PhotoOfTheWeekProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

use function array_column;
use function array_key_exists;
use function array_keys;
use function dirname;
use function file_get_contents;
use function ob_get_clean;
use function ob_start;
use function strtr;

#[CoversClass(AlbumResource::class)]
#[CoversClass(PhotoResource::class)]
#[CoversClass(PhotoOfTheWeekResource::class)]
#[CoversClass(AlbumProvider::class)]
#[CoversClass(AlbumPhotoProvider::class)]
#[CoversClass(PhotoOfTheWeekProvider::class)]
#[CoversClass(ApiController::class)]
final class PhotoApiTest extends ApiTestCase
{
    private const array ALBUM_KEYS = [
        'id',
        'name',
        'startDateTime',
        'endDateTime',
        'parent',
        'photoCount',
        'albumCount',
        'coverUrl',
        'children',
        'photosUrl',
    ];

    private const array CHILD_KEYS = [
        'id',
        'name',
        'startDateTime',
        'endDateTime',
        'photoCount',
        'albumCount',
        'coverUrl',
    ];

    private const array PHOTO_KEYS = [
        'id',
        'dateTime',
        'artist',
        'camera',
        'aspectRatio',
        'url',
        'tags',
    ];

    private const array PHOTO_OF_THE_WEEK_KEYS = [
        'week',
        'photo',
        'url',
    ];

    private const array PHOTO_OF_THE_WEEK_PHOTO_KEYS = [
        'id',
        'dateTime',
        'artist',
        'camera',
        'aspectRatio',
        'album',
    ];

    private const array META_KEYS = [
        'page',
        'itemsPerPage',
        'totalItems',
        'totalPages',
    ];

    #[DataProvider('gatedOperations')]
    public function testAnOperationIsClosedToAPrincipalThatDoesNotHoldItsPermission(
        string $path,
        ApiPermissions $permission,
    ): void {
        $response = $this->get(
            $this->resolve($path),
            $this->principalWith([ApiPermissions::HealthR]),
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );
        self::assertSame(
            [
                'status' => 'forbidden',
                'error' => [
                    'type' => 'User\\Model\\Exception\\NotAllowed',
                    'exception' => 'Permission `' . $permission->value . '` is needed but is not currently held.',
                ],
            ],
            $this->json($response),
        );
    }

    #[DataProvider('gatedPaths')]
    public function testAnOperationAnswersNothingAtAllWithoutAToken(string $path): void
    {
        $response = $this->get($this->resolve($path));

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );
        self::assertSame(
            '',
            (string) $response->getContent(),
        );
    }

    #[DataProvider('versionedOperations')]
    public function testAnOperationRefusesAConsumerThatStatesNoContractVersion(
        string $path,
        ApiPermissions $permission,
    ): void {
        $response = $this->get(
            $this->resolve($path),
            $this->principalWith([$permission]),
            withVersion: false,
        );

        self::assertSame(
            Response::HTTP_NOT_ACCEPTABLE,
            $response->getStatusCode(),
        );
        self::assertSame(
            [
                'status' => 'error',
                'error' => [
                    'type' => 'Database\\Model\\Exception\\VersionExpected',
                    'exception' => 'API version expected, but none was given',
                ],
            ],
            $this->json($response),
        );
    }

    /**
     * @return iterable<string, array{string, ApiPermissions}>
     */
    public static function versionedOperations(): iterable
    {
        yield 'the albums' => [
            '/api/photos/albums',
            ApiPermissions::PhotoAlbumsR,
        ];

        yield 'one album' => [
            '/api/photos/albums/{album}',
            ApiPermissions::PhotoAlbumsR,
        ];

        yield 'the photos of an album' => [
            '/api/photos/albums/{album}/photos',
            ApiPermissions::PhotoAlbumsR,
        ];

        yield 'the photo of the week' => [
            '/api/photos/photo-of-the-week',
            ApiPermissions::PhotoOfTheWeekR,
        ];
    }

    /**
     * @return iterable<string, array{string, ApiPermissions}>
     */
    public static function gatedOperations(): iterable
    {
        yield from self::versionedOperations();

        yield 'the image of a photo' => [
            '/api/photos/{photo}/image/{variant}',
            ApiPermissions::PhotoAlbumsR,
        ];

        yield 'the cover of an album' => [
            '/api/photos/albums/{album}/cover/{cover}',
            ApiPermissions::PhotoAlbumsR,
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gatedPaths(): iterable
    {
        foreach (self::gatedOperations() as $name => $operation) {
            yield $name => [$operation[0]];
        }
    }

    public function testAnAlbumRowNamesEveryFieldInTheOrderItPromises(): void
    {
        $body = $this->json($this->get(
            '/api/photos/albums',
            $this->principalWith([ApiPermissions::PhotoAlbumsR]),
            ['itemsPerPage' => 1],
        ));

        self::assertSame(
            'success',
            $body['status'],
        );
        self::assertSame(
            self::ALBUM_KEYS,
            array_keys($body['data'][0]),
        );
        self::assertSame(
            self::META_KEYS,
            array_keys($body['meta']),
        );
    }

    public function testTheListingLeavesTheTreeAndThePhotoAddressToTheAlbumsOwnUrl(): void
    {
        $body = $this->json($this->get(
            '/api/photos/albums',
            $this->principalWith([ApiPermissions::PhotoAlbumsR]),
            ['itemsPerPage' => 500],
        ));

        foreach ($body['data'] as $row) {
            self::assertNull($row['children']);
            self::assertNull($row['photosUrl']);
        }
    }

    public function testASingleAlbumCarriesItsPublishedChildrenAndTheAddressOfItsPhotos(): void
    {
        $album = $this->aPublishedAlbumWithBothKindsOfChild();

        $response = $this->get(
            '/api/photos/albums/' . $album,
            $this->principalWith([ApiPermissions::PhotoAlbumsR]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertSame(
            self::ALBUM_KEYS,
            array_keys($body['data']),
        );
        self::assertArrayNotHasKey(
            'meta',
            $body,
        );
        self::assertNotEmpty($body['data']['children']);
        self::assertSame(
            self::CHILD_KEYS,
            array_keys($body['data']['children'][0]),
        );
        self::assertNotContains(
            $this->anUnpublishedChildAlbumId(),
            array_column(
                $body['data']['children'],
                'id',
            ),
        );
        self::assertStringEndsWith(
            '/api/photos/albums/' . $album . '/photos',
            $body['data']['photosUrl'],
        );
    }

    public function testAPhotoRowNamesEveryFieldInTheOrderItPromises(): void
    {
        $body = $this->json($this->get(
            '/api/photos/albums/' . $this->aPublishedAlbumWithSeveralPhotos() . '/photos',
            $this->principalWith([ApiPermissions::PhotoAlbumsR]),
            ['itemsPerPage' => 1],
        ));

        self::assertSame(
            'success',
            $body['status'],
        );
        self::assertSame(
            self::PHOTO_KEYS,
            array_keys($body['data'][0]),
        );
        self::assertSame(
            self::META_KEYS,
            array_keys($body['meta']),
        );
    }

    public function testThePhotoOfTheWeekIsOneRowWithItsAlbumAndCarriesNoPage(): void
    {
        $response = $this->get(
            '/api/photos/photo-of-the-week',
            $this->principalWith([ApiPermissions::PhotoOfTheWeekR]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertSame(
            'success',
            $body['status'],
        );
        self::assertSame(
            self::PHOTO_OF_THE_WEEK_KEYS,
            array_keys($body['data']),
        );
        self::assertSame(
            self::PHOTO_OF_THE_WEEK_PHOTO_KEYS,
            array_keys($body['data']['photo']),
        );
        self::assertSame(
            [
                'id',
                'name',
            ],
            array_keys($body['data']['photo']['album']),
        );
        self::assertArrayNotHasKey(
            'meta',
            $body,
        );
    }

    public function testThePublicCopyOfThePhotoOfTheWeekIsAddressedOnlyWhileItIsStored(): void
    {
        $token = $this->principalWith([ApiPermissions::PhotoOfTheWeekR]);

        $withoutCopy = $this->json($this->get(
            '/api/photos/photo-of-the-week',
            $token,
        ));
        self::assertNull(
            $withoutCopy['data']['url'],
            'nothing has been copied into the public namespace yet',
        );

        $path = $this->publishThePhotoOfTheWeek();

        $withCopy = $this->json($this->get(
            '/api/photos/photo-of-the-week',
            $token,
        ));
        self::assertIsString($withCopy['data']['url']);
        self::assertStringContainsString(
            '/' . $path,
            $withCopy['data']['url'],
        );
    }

    public function testTheAlbumsAndThePhotosOfOneBothPage(): void
    {
        $token = $this->principalWith([ApiPermissions::PhotoAlbumsR]);

        foreach (
            [
                '/api/photos/albums',
                '/api/photos/albums/' . $this->aPublishedAlbumWithSeveralPhotos() . '/photos',
            ] as $path
        ) {
            $first = $this->json($this->get(
                $path,
                $token,
                ['itemsPerPage' => 1],
            ));
            $second = $this->json($this->get(
                $path,
                $token,
                [
                    'itemsPerPage' => 1,
                    'page' => 2,
                ],
            ));

            self::assertCount(
                1,
                $first['data'],
                $path,
            );
            self::assertSame(
                1,
                $first['meta']['page'],
                $path,
            );
            self::assertSame(
                2,
                $second['meta']['page'],
                $path,
            );
            self::assertSame(
                $first['meta']['totalItems'],
                $second['meta']['totalItems'],
                $path,
            );
            self::assertNotSame(
                $first['data'][0]['id'],
                $second['data'][0]['id'],
                $path,
            );
        }
    }

    public function testAPageOutOfRangeIsClampedRatherThanRefused(): void
    {
        $token = $this->principalWith([ApiPermissions::PhotoAlbumsR]);

        $tooLarge = $this->json($this->get(
            '/api/photos/albums',
            $token,
            ['itemsPerPage' => 100000],
        ));
        self::assertSame(
            500,
            $tooLarge['meta']['itemsPerPage'],
        );

        $tooSmall = $this->json($this->get(
            '/api/photos/albums',
            $token,
            [
                'itemsPerPage' => 0,
                'page' => -3,
            ],
        ));
        self::assertSame(
            1,
            $tooSmall['meta']['page'],
        );
        self::assertSame(
            1,
            $tooSmall['meta']['itemsPerPage'],
        );

        $pastTheEnd = $this->get(
            '/api/photos/albums',
            $token,
            ['page' => 99999],
        );
        self::assertSame(
            Response::HTTP_OK,
            $pastTheEnd->getStatusCode(),
        );
        self::assertSame(
            [],
            $this->json($pastTheEnd)['data'],
        );
    }

    #[DataProvider('addressesOfOneAlbum')]
    public function testAnUnknownAlbumIsAMissingResource(string $template): void
    {
        $response = $this->get(
            strtr(
                $template,
                ['{album}' => '99999999'],
            ),
            $this->principalWith([ApiPermissions::PhotoAlbumsR]),
        );

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            [
                'status' => 'notfound',
                'error' => [
                    'type' => 'error-resource-not-found',
                    'exception' => 'Not Found',
                ],
            ],
            $this->json($response),
        );
    }

    #[DataProvider('addressesOfOneAlbum')]
    public function testAnAlbumIdOfTheWrongShapeReachesNoOperationAtAll(string $template): void
    {
        $response = $this->get(
            strtr(
                $template,
                ['{album}' => 'seventeen'],
            ),
            $this->principalWith([ApiPermissions::PhotoAlbumsR]),
        );

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            [
                'status' => 'notfound',
                'error' => [
                    'type' => 'error-router-no-match',
                    'exception' => null,
                ],
            ],
            $this->json($response),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesOfOneAlbum(): iterable
    {
        yield 'the album itself' => ['/api/photos/albums/{album}'];

        yield 'the photos of the album' => ['/api/photos/albums/{album}/photos'];
    }

    public function testAnUnpublishedAlbumIsNotListedAndAnswersNowhere(): void
    {
        $token = $this->principalWith([ApiPermissions::PhotoAlbumsR]);
        $album = $this->anUnpublishedAlbumId();

        $listing = $this->json($this->get(
            '/api/photos/albums',
            $token,
            ['itemsPerPage' => 500],
        ));
        self::assertNotContains(
            $album,
            array_column(
                $listing['data'],
                'id',
            ),
        );

        foreach (
            [
                '/api/photos/albums/' . $album,
                '/api/photos/albums/' . $album . '/photos',
            ] as $path
        ) {
            $response = $this->get(
                $path,
                $token,
            );

            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $response->getStatusCode(),
                $path,
            );
            self::assertSame(
                'error-resource-not-found',
                $this->json($response)['error']['type'],
                $path,
            );
        }
    }

    public function testTheImageOfAPhotoIsServedAsWebPBytesAndAsksForNoContractVersion(): void
    {
        $photo = $this->aPhotoInAPublishedAlbum();
        $this->store($photo->getPath());
        $this->pregenerate(
            $photo->getPath(),
            ImageVariant::W320,
        );

        $response = $this->get(
            $this->imagePath($photo),
            $this->principalWith([ApiPermissions::PhotoAlbumsR]),
            withVersion: false,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            'image/webp',
            $response->headers->get('Content-Type'),
        );
        self::assertStringStartsWith(
            'RIFF',
            $this->bytes($response),
        );
    }

    public function testTheCoverOfAnAlbumIsServedAsWebPBytesAndAsksForNoContractVersion(): void
    {
        $album = $this->aPublishedAlbumWithSeveralPhotos();
        $path = StorageNamespace::PhotoCover->directory((string) $album) . '/mosaic.jpg';
        $this->store($path);
        $this->pregenerate(
            $path,
            ImageVariant::Cover,
        );
        $this->entityManager->find(
            AlbumEntity::class,
            $album,
        )?->setCoverPath($path);
        $this->entityManager->flush();

        $response = $this->get(
            '/api/photos/albums/' . $album . '/cover/' . ImageVariant::Cover->value,
            $this->principalWith([ApiPermissions::PhotoAlbumsR]),
            withVersion: false,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            'image/webp',
            $response->headers->get('Content-Type'),
        );
        self::assertStringStartsWith(
            'RIFF',
            $this->bytes($response),
        );
    }

    public function testAnAlbumWithACoverAddressesItAndOneWithoutDoesNot(): void
    {
        $token = $this->principalWith([ApiPermissions::PhotoAlbumsR]);
        $album = $this->aPublishedAlbumWithSeveralPhotos();

        $without = $this->json($this->get(
            '/api/photos/albums/' . $album,
            $token,
        ));
        self::assertNull($without['data']['coverUrl']);

        $this->entityManager->find(
            AlbumEntity::class,
            $album,
        )?->setCoverPath(StorageNamespace::PhotoCover->directory((string) $album) . '/mosaic.jpg');
        $this->entityManager->flush();

        $with = $this->json($this->get(
            '/api/photos/albums/' . $album,
            $token,
        ));
        self::assertStringEndsWith(
            '/api/photos/albums/' . $album . '/cover/' . ImageVariant::Cover->value,
            $with['data']['coverUrl'],
        );
    }

    public function testAPhotoOfAnUnpublishedAlbumHasNoImageToServe(): void
    {
        $photo = $this->aPhotoInAnUnpublishedAlbum();
        $this->store($photo->getPath());

        $response = $this->get(
            $this->imagePath($photo),
            $this->principalWith([ApiPermissions::PhotoAlbumsR]),
        );

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            'error-resource-not-found',
            $this->json($response)['error']['type'],
        );
    }

    public function testAnImageAddressThatNamesNothingIsAMissingResource(): void
    {
        $token = $this->principalWith([ApiPermissions::PhotoAlbumsR]);

        foreach (
            [
                '/api/photos/99999999/image/' . ImageVariant::W320->value,
                '/api/photos/' . $this->aPhotoInAPublishedAlbum()->getId() . '/image/w9999',
            ] as $path
        ) {
            $response = $this->get(
                $path,
                $token,
            );

            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $response->getStatusCode(),
                $path,
            );
            self::assertSame(
                'error-resource-not-found',
                $this->json($response)['error']['type'],
                $path,
            );
        }
    }

    public function testAnImageAddressOfTheWrongShapeReachesNoOperationAtAll(): void
    {
        $token = $this->principalWith([ApiPermissions::PhotoAlbumsR]);

        foreach (
            [
                '/api/photos/seventeen/image/' . ImageVariant::W320->value,
                '/api/photos/' . $this->aPhotoInAPublishedAlbum()->getId() . '/image/W320',
            ] as $path
        ) {
            $response = $this->get(
                $path,
                $token,
            );

            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $response->getStatusCode(),
                $path,
            );
            self::assertSame(
                'error-router-no-match',
                $this->json($response)['error']['type'],
                $path,
            );
        }
    }

    private function imagePath(PhotoEntity $photo): string
    {
        return '/api/photos/' . $photo->getId() . '/image/' . ImageVariant::W320->value;
    }

    private function bytes(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /** Serving never encodes: a variant must exist before the request, or the endpoint answers 503. */
    private function pregenerate(
        string $path,
        ImageVariant $variant,
    ): void {
        self::getContainer()->get(VariantGenerator::class)->generateVariant(
            $path,
            $variant,
            85,
            skipUpscale: false,
        );
    }

    private function store(string $path): void
    {
        self::getContainer()->get(FileStorage::class)->write(
            $path,
            (string) file_get_contents(
                dirname(
                    __DIR__,
                    3,
                ) . '/tests/Resources/images/gala-dinner-1.jpg',
            ),
        );
    }

    private function publishThePhotoOfTheWeek(): string
    {
        $weeklyPhoto = self::getContainer()->get(WeeklyPhotoRepository::class)->getCurrentPhotoOfTheWeek();
        self::assertNotNull($weeklyPhoto);

        $path = self::getContainer()->get(WeeklyPhotoService::class)->publicPathFor($weeklyPhoto->getPhoto());
        $this->store($path);

        return $path;
    }

    private function resolve(string $template): string
    {
        return strtr(
            $template,
            [
                '{album}' => (string) $this->aPublishedAlbumWithSeveralPhotos(),
                '{photo}' => (string) $this->aPhotoInAPublishedAlbum()->getId(),
                '{variant}' => ImageVariant::W320->value,
                '{cover}' => ImageVariant::Cover->value,
            ],
        );
    }

    public function testAMemberTagNamesADeletedMemberOnlyWhenThePrincipalMaySeeOne(): void
    {
        $album = $this->aPublishedAlbumWithATaggedMember();
        $lidnr = $this->deleteTheMemberTaggedIn($album);

        $withoutDeleted = $this->taggedLidnrs(
            $album,
            $this->principalWith([ApiPermissions::PhotoAlbumsR]),
        );
        self::assertNotContains(
            $lidnr,
            $withoutDeleted,
        );

        $withDeleted = $this->taggedLidnrs(
            $album,
            $this->principalWith([
                ApiPermissions::PhotoAlbumsR,
                ApiPermissions::MembersDeleted,
            ]),
        );
        self::assertContains(
            $lidnr,
            $withDeleted,
        );
    }

    /**
     * @return list<int>
     */
    private function taggedLidnrs(
        int $album,
        string $token,
    ): array {
        $lidnrs = [];

        foreach (
            $this->json($this->get(
                '/api/photos/albums/' . $album . '/photos',
                $token,
                ['itemsPerPage' => 500],
            ))['data'] as $photo
        ) {
            foreach ($photo['tags'] as $tag) {
                if (
                    !array_key_exists(
                        'lidnr',
                        $tag,
                    )
                ) {
                    continue;
                }

                $lidnrs[] = $tag['lidnr'];
            }
        }

        return $lidnrs;
    }

    private function aPublishedAlbumWithATaggedMember(): int
    {
        return (int) $this->scalar(
            'SELECT a.id FROM ' . AlbumEntity::class . ' a JOIN a.photos p JOIN p.tags t'
            . ' WHERE a.published = true AND t INSTANCE OF ' . MemberTag::class . ' ORDER BY a.id ASC',
        );
    }

    private function deleteTheMemberTaggedIn(int $album): int
    {
        $tag = $this->entityManager->createQuery(
            'SELECT t FROM ' . MemberTag::class . ' t JOIN t.photo p JOIN p.album a'
            . ' WHERE a.id = :album ORDER BY t.id ASC',
        )
            ->setParameter(
                'album',
                $album,
            )
            ->setMaxResults(1)
            ->getSingleResult();
        self::assertInstanceOf(
            MemberTag::class,
            $tag,
        );

        $tag->getMember()->setDeleted(true);
        $this->entityManager->flush();

        return $tag->getMember()->getLidnr();
    }

    private function aPublishedAlbumWithSeveralPhotos(): int
    {
        return (int) $this->scalar(
            'SELECT a.id FROM ' . AlbumEntity::class . ' a JOIN a.photos p'
            . ' WHERE a.published = true GROUP BY a.id HAVING COUNT(p.id) > 1 ORDER BY a.id ASC',
        );
    }

    private function aPublishedAlbumWithBothKindsOfChild(): int
    {
        return (int) $this->scalar(
            'SELECT a.id FROM ' . AlbumEntity::class . ' a JOIN a.children c'
            . ' WHERE a.published = true AND c.published = false ORDER BY a.id ASC',
        );
    }

    private function anUnpublishedChildAlbumId(): int
    {
        return (int) $this->scalar(
            'SELECT c.id FROM ' . AlbumEntity::class . ' c JOIN c.parent a'
            . ' WHERE c.published = false AND a.published = true ORDER BY c.id ASC',
        );
    }

    private function anUnpublishedAlbumId(): int
    {
        return (int) $this->scalar(
            'SELECT a.id FROM ' . AlbumEntity::class . ' a JOIN a.photos p'
            . ' WHERE a.published = false ORDER BY a.id ASC',
        );
    }

    private function aPhotoInAPublishedAlbum(): PhotoEntity
    {
        return $this->photo(true);
    }

    private function aPhotoInAnUnpublishedAlbum(): PhotoEntity
    {
        return $this->photo(false);
    }

    private function photo(bool $published): PhotoEntity
    {
        $photo = $this->entityManager->createQuery(
            'SELECT p FROM ' . PhotoEntity::class . ' p JOIN p.album a'
            . ' WHERE a.published = :published ORDER BY p.id ASC',
        )
            ->setParameter(
                'published',
                $published,
            )
            ->setMaxResults(1)
            ->getSingleResult();
        self::assertInstanceOf(
            PhotoEntity::class,
            $photo,
        );

        return $photo;
    }

    private function scalar(string $dql): mixed
    {
        return $this->entityManager->createQuery($dql)
            ->setMaxResults(1)
            ->getSingleScalarResult();
    }
}
