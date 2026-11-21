<?php

declare(strict_types=1);

namespace App\Tests\Security\Photo;

use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Photo\Album;
use App\Entity\Photo\Photo;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Photo\AlbumRepository;
use App\Repository\Photo\PhotoRepository;
use App\Security\Photo\AlbumVoter;
use App\Security\Photo\PhotoServingAccessChecker;
use App\Security\Photo\PhotoVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

use function in_array;

/**
 * A cover is made of members-only photos, so it has the same gate. Graduates get no fast path, so a leaked URL cannot
 * bypass their membership cutoff.
 */
final class PhotoServingAccessCheckerTest extends TestCase
{
    public function testOnlyTheMembersOnlyPhotoNamespacesAreSupported(): void
    {
        $checker = $this->checker(member: false);

        self::assertTrue($checker->supports(StorageNamespace::PhotoOriginal));
        self::assertTrue($checker->supports(StorageNamespace::PhotoCover));
        self::assertFalse($checker->supports(StorageNamespace::PageImage));
    }

    public function testAFullMemberGetsTheFastPath(): void
    {
        self::assertTrue(
            $this->checker(member: true)->isGranted(
                'photos/albums/1/a.jpg',
                StorageNamespace::PhotoOriginal,
            ),
        );
    }

    public function testAGraduateMayViewAPhotoTheVoterAllows(): void
    {
        $photo = self::createStub(Photo::class);

        $checker = $this->checker(
            member: false,
            graduate: true,
            photo: $photo,
            granted: [PhotoVoter::VIEW],
        );

        self::assertTrue($checker->isGranted('photos/albums/1/a.jpg', StorageNamespace::PhotoOriginal));
    }

    public function testAGraduateMayNotViewAPhotoThatIsNotThere(): void
    {
        $checker = $this->checker(
            member: false,
            graduate: true,
            photo: null,
        );

        self::assertFalse($checker->isGranted('photos/albums/1/a.jpg', StorageNamespace::PhotoOriginal));
    }

    public function testAGraduateMayViewACoverTheVoterAllows(): void
    {
        $album = self::createStub(Album::class);

        $checker = $this->checker(
            member: false,
            graduate: true,
            album: $album,
            granted: [AlbumVoter::VIEW],
        );

        self::assertTrue($checker->isGranted('photos/covers/1.jpg', StorageNamespace::PhotoCover));
    }

    public function testAGraduateMayNotViewACoverThatIsNotThere(): void
    {
        $checker = $this->checker(
            member: false,
            graduate: true,
            album: null,
        );

        self::assertFalse($checker->isGranted('photos/covers/1.jpg', StorageNamespace::PhotoCover));
    }

    public function testAnAnonymousVisitorIsDenied(): void
    {
        self::assertFalse(
            $this->checker(member: false)->isGranted(
                'photos/albums/1/a.jpg',
                StorageNamespace::PhotoOriginal,
            ),
        );
    }

    /**
     * @param list<string> $granted
     */
    private function checker(
        bool $member,
        bool $graduate = false,
        ?Photo $photo = null,
        ?Album $album = null,
        array $granted = [],
    ): PhotoServingAccessChecker {
        $security = self::createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static function (mixed $attribute) use ($member, $graduate, $granted): bool {
                if (UserRoles::Member->value === $attribute) {
                    return $member;
                }

                if (UserRoles::Graduate->value === $attribute) {
                    return $graduate;
                }

                return in_array(
                    $attribute,
                    $granted,
                    true,
                );
            },
        );

        $photos = self::createStub(PhotoRepository::class);
        $photos->method('findOneBy')->willReturn($photo);

        $albums = self::createStub(AlbumRepository::class);
        $albums->method('findOneBy')->willReturn($album);

        return new PhotoServingAccessChecker(
            $security,
            $photos,
            $albums,
        );
    }
}
