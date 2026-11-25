<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Photo;

use App\Entity\Decision\Member;
use App\Entity\Photo\HiddenPhoto;
use App\Entity\Photo\Photo;
use App\Repository\Photo\HiddenPhotoRepository;
use App\Tests\Integration\DatabaseTestCase;
use Override;

use function array_map;

final class HiddenPhotoRepositoryTest extends DatabaseTestCase
{
    private HiddenPhotoRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(HiddenPhotoRepository::class);
    }

    public function testHiddenPhotoIdsAreASetForOneMemberOnly(): void
    {
        $photos = $this->photos(2);
        $member = $this->member(8030);
        $other = $this->member(8031);

        $this->hide(
            $member,
            $photos[0],
        );
        $this->hide(
            $other,
            $photos[1],
        );

        $ids = $this->repository->getHiddenPhotoIds($member);

        self::assertSame(
            [(int) $photos[0]->id => true],
            $ids,
        );
        self::assertSame(
            [],
            $this->repository->getHiddenPhotoIds($this->member(8032)),
        );
    }

    public function testFindByMemberAndPhotoReturnsOnlyTheMatchingRow(): void
    {
        $photos = $this->photos(2);
        $member = $this->member(8030);

        $row = $this->hide(
            $member,
            $photos[0],
        );

        $found = $this->repository->findByMemberAndPhoto(
            $member,
            $photos[0],
        );

        self::assertSame(
            $row,
            $found,
        );
        self::assertNull($this->repository->findByMemberAndPhoto(
            $member,
            $photos[1],
        ));
    }

    public function testFindByMemberAndPhotosBatchesAndReturnsNothingForAnEmptyList(): void
    {
        $photos = $this->photos(3);
        $member = $this->member(8030);

        $first = $this->hide(
            $member,
            $photos[0],
        );
        $second = $this->hide(
            $member,
            $photos[1],
        );
        $this->hide(
            $this->member(8031),
            $photos[2],
        );

        $rows = $this->repository->findByMemberAndPhotos(
            $member,
            array_map(
                static fn (Photo $photo): int => (int) $photo->id,
                $photos,
            ),
        );

        self::assertEqualsCanonicalizing(
            [
                $first,
                $second,
            ],
            $rows,
        );
        self::assertSame(
            [],
            $this->repository->findByMemberAndPhotos(
                $member,
                [],
            ),
        );
    }

    private function hide(
        Member $member,
        Photo $photo,
    ): HiddenPhoto {
        $hidden = new HiddenPhoto();
        $hidden->member = $member;
        $hidden->photo = $photo;

        $this->entityManager->persist($hidden);
        $this->entityManager->flush();

        return $hidden;
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
     * @return list<Photo>
     */
    private function photos(int $count): array
    {
        $repository = $this->entityManager->getRepository(Photo::class);
        $photos = $repository->findBy(
            [],
            null,
            $count,
        );

        self::assertCount(
            $count,
            $photos,
        );

        return $photos;
    }
}
