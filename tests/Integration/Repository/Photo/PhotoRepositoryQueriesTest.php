<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Photo;

use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use App\Entity\Photo\Album;
use App\Entity\Photo\MemberAlbum;
use App\Entity\Photo\MemberTag;
use App\Entity\Photo\OrganAlbum;
use App\Entity\Photo\OrganTag;
use App\Entity\Photo\Photo;
use App\Repository\Decision\OrganRepository;
use App\Repository\Photo\PhotoRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Override;

use function array_map;
use function count;
use function iterator_to_array;

/**
 * Reading photos back out of an album, and out of the virtual albums a member or a body is: which photos are in one, in
 * what order, and how many of them there are.
 */
final class PhotoRepositoryQueriesTest extends DatabaseTestCase
{
    private PhotoRepository $repository;

    private Album $album;

    private Photo $later;

    private Photo $earlier;

    private Photo $elsewhere;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(PhotoRepository::class);

        $this->album = new Album();
        $this->album->name = 'Photo Repository Test';
        $this->album->published = true;
        $this->album->startDateTime = new DateTimeImmutable('2032-01-01 10:00');
        $this->album->endDateTime = $this->album->startDateTime;

        $other = new Album();
        $other->name = 'Photo Repository Test - Elsewhere';
        $other->published = true;
        $other->startDateTime = new DateTimeImmutable('2032-02-01 10:00');
        $other->endDateTime = $other->startDateTime;

        $this->entityManager->persist($this->album);
        $this->entityManager->persist($other);

        $this->later = $this->photo(
            $this->album,
            '2032-01-01 12:00',
            'photo-repo-test/later.jpg',
        );
        $this->earlier = $this->photo(
            $this->album,
            '2032-01-01 09:00',
            'photo-repo-test/earlier.jpg',
        );
        $this->later->artist = 'Test Artist';
        $this->elsewhere = $this->photo(
            $other,
            '2032-02-01 09:00',
            'photo-repo-test/elsewhere.jpg',
        );

        $this->entityManager->flush();
    }

    public function testAnAlbumListsItsPhotosInTheOrderTheyWereTaken(): void
    {
        self::assertSame(
            [
                $this->earlier->id,
                $this->later->id,
            ],
            $this->ids($this->repository->getAlbumPhotos($this->album)),
        );
    }

    public function testAnAlbumCanBeReadFromAPosition(): void
    {
        self::assertSame(
            [$this->later->id],
            $this->ids($this->repository->getAlbumPhotos(
                $this->album,
                1,
            )),
        );

        self::assertSame(
            [$this->earlier->id],
            $this->ids($this->repository->getAlbumPhotos(
                $this->album,
                0,
                1,
            )),
        );
    }

    public function testAMemberAlbumHoldsWhatThatMemberIsTaggedInNewestFirst(): void
    {
        $member = $this->member(8030);
        $tag = new MemberTag();
        $tag->photo = $this->later;
        $tag->member = $member;
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        $photos = $this->repository->getAlbumPhotos(new MemberAlbum(
            0,
            $member,
        ));
        $ids = $this->ids($photos);

        self::assertContains(
            $this->later->id,
            $ids,
        );
        // A photo in the same album that the member is not tagged in is not theirs.
        self::assertNotContains(
            $this->earlier->id,
            $ids,
        );

        $dates = array_map(
            static fn (Photo $photo): int => $photo->dateTime->getTimestamp(),
            $photos,
        );
        $previous = null;
        foreach ($dates as $date) {
            if (null !== $previous) {
                self::assertLessThanOrEqual(
                    $previous,
                    $date,
                );
            }

            $previous = $date;
        }
    }

    public function testAnOrganAlbumHoldsWhatThatBodyIsTaggedIn(): void
    {
        $organ = self::getContainer()->get(OrganRepository::class)->findActive()[0] ?? null;
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        $tag = new OrganTag();
        $tag->photo = $this->earlier;
        $tag->organ = $organ;
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        $ids = $this->ids($this->repository->getAlbumPhotos(new OrganAlbum(
            0,
            $organ,
        )));

        self::assertContains(
            $this->earlier->id,
            $ids,
        );
        self::assertNotContains(
            $this->later->id,
            $ids,
        );
    }

    public function testRandomPhotosStayInsideTheAlbumsTheyAreAskedFrom(): void
    {
        $photos = $this->repository->getRandomPhotosFromAlbums(
            [$this->album],
            1,
        );

        self::assertCount(
            1,
            $photos,
        );
        self::assertSame(
            $this->album,
            $photos[0]->album,
        );

        // Asking for more than there are returns what there is rather than failing.
        self::assertCount(
            2,
            $this->repository->getRandomPhotosFromAlbums(
                [$this->album],
                10,
            ),
        );
    }

    public function testAPhotoIsFoundByItsPathWithinItsAlbum(): void
    {
        self::assertSame(
            $this->later,
            $this->repository->getPhotoByData(
                'photo-repo-test/later.jpg',
                $this->album,
            ),
        );
        // The same path in another album is not the same photo.
        self::assertNull($this->repository->getPhotoByData(
            'photo-repo-test/later.jpg',
            $this->elsewhere->album,
        ));
    }

    public function testResolvingIdsIsSkippedWhenThereAreNone(): void
    {
        self::assertSame(
            [$this->later->id],
            $this->ids($this->repository->findByIds([(int) $this->later->id])),
        );
        self::assertSame(
            [],
            $this->repository->findByIds([]),
        );
    }

    public function testDirectPhotoCountsAreKeyedByAlbum(): void
    {
        $counts = $this->repository->getDirectPhotoCounts([
            $this->album,
            $this->elsewhere->album,
        ]);

        self::assertCount(
            2,
            $counts,
        );
        self::assertSame(
            2,
            $counts[(int) $this->album->id],
        );
        self::assertSame(
            1,
            $counts[(int) $this->elsewhere->album->id],
        );

        self::assertSame(
            [],
            $this->repository->getDirectPhotoCounts([]),
        );
    }

    public function testAPhotosAuthorIsMatchedByName(): void
    {
        $author = new Member();
        $author->firstName = 'Test';
        $author->middleName = '';
        $author->lastName = 'Artist';

        self::assertSame(
            [$this->later->id],
            $this->ids($this->repository->findPhotosByMember($author)),
        );

        $someoneElse = new Member();
        $someoneElse->firstName = 'Nobody';
        $someoneElse->middleName = '';
        $someoneElse->lastName = 'Atall';

        self::assertSame(
            [],
            $this->repository->findPhotosByMember($someoneElse),
        );
    }

    public function testPagingAnAlbumCountsEveryPhotoButReturnsOnePage(): void
    {
        $first = $this->repository->paginateAlbumPhotos(
            $this->album,
            1,
            1,
        );

        // The count is over the whole album, not only the page that was fetched.
        self::assertCount(
            2,
            $first,
        );
        self::assertSame(
            [$this->earlier->id],
            $this->ids(iterator_to_array($first, false)),
        );

        self::assertSame(
            [$this->later->id],
            $this->ids(iterator_to_array(
                $this->repository->paginateAlbumPhotos(
                    $this->album,
                    2,
                    1,
                ),
                false,
            )),
        );
    }

    private function photo(
        Album $album,
        string $dateTime,
        string $path,
    ): Photo {
        $photo = new Photo();
        $photo->album = $album;
        $photo->dateTime = new DateTimeImmutable($dateTime);
        $photo->path = $path;
        $photo->aspectRatio = 0.75;

        $this->entityManager->persist($photo);

        return $photo;
    }

    private function member(int $lidnr): Member
    {
        $member = $this->entityManager->find(
            Member::class,
            $lidnr,
        );
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }

    /**
     * @param Photo[] $photos
     *
     * @return list<int|null>
     */
    private function ids(array $photos): array
    {
        $ids = [];
        foreach ($photos as $photo) {
            $ids[] = $photo->id;
        }

        self::assertCount(
            count($photos),
            $ids,
        );

        return $ids;
    }
}
