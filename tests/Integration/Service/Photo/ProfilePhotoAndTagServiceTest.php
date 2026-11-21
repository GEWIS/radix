<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Photo;

use App\Entity\Decision\Member;
use App\Entity\Photo\MemberTag;
use App\Entity\Photo\Photo;
use App\Entity\User\User;
use App\Entity\User\UserSettings;
use App\Repository\Decision\OrganRepository;
use App\Repository\Photo\AlbumRepository;
use App\Repository\Photo\HiddenPhotoRepository;
use App\Repository\Photo\MemberTagRepository;
use App\Repository\Photo\PhotoRepository;
use App\Repository\Photo\ProfilePhotoRepository;
use App\Repository\User\UserSettingsRepository;
use App\Service\Photo\PhotoPrivacyService;
use App\Service\Photo\ProfilePhotoService;
use App\Service\Photo\TagService;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

use function array_values;

/**
 * A profile photo may only be one the member appears in and has not hidden, and tagging enforces the data rules the
 * voter cannot: the subject exists, is still active, and is not already on the photo.
 */
final class ProfilePhotoAndTagServiceTest extends DatabaseTestCase
{
    public function testAProfilePhotoIsKeptForAYearAndReplacesTheOldOne(): void
    {
        $member = $this->member(8030);
        $tag = $this->tagsOf(8030)[0];

        self::assertTrue($this->profiles()->setProfilePhoto($tag->photo, $member));

        $stored = self::getContainer()->get(ProfilePhotoRepository::class)->getProfilePhotoByLidnr(8030);
        self::assertNotNull($stored);
        self::assertSame(
            $tag->photo->id,
            $stored->photo->id,
        );
        self::assertTrue($stored->explicit);
        self::assertGreaterThan(
            new DateTimeImmutable('now +350 days'),
            $stored->dateTime,
        );
    }

    /**
     * A photo the member is not tagged in, or has hidden from their page, is refused rather than silently ignored.
     */
    public function testAPhotoTheMemberIsNotTaggedInIsRefused(): void
    {
        $member = $this->member(8030);
        $other = $this->tagsOf(8031)[0];

        $before = self::getContainer()->get(ProfilePhotoRepository::class)->getProfilePhotoByLidnr(8030);

        self::assertFalse($this->profiles()->setProfilePhoto($other->photo, $member));

        // The seeded profile photo is untouched.
        self::assertSame(
            $before?->id,
            self::getContainer()->get(ProfilePhotoRepository::class)->getProfilePhotoByLidnr(8030)?->id,
        );
    }

    public function testAHiddenPhotoCannotBecomeTheProfilePhoto(): void
    {
        $member = $this->member(8030);
        $tag = $this->tagsOf(8030)[0];
        $this->privacy()->hide(
            $member,
            [$tag->photo],
        );

        self::assertFalse($this->profiles()->setProfilePhoto($tag->photo, $member));
    }

    /**
     * Hiding the photo that is currently the profile photo clears it, so a hidden photo never stays on show.
     */
    public function testHidingTheProfilePhotoClearsIt(): void
    {
        $member = $this->member(8030);
        $tag = $this->tagsOf(8030)[0];
        self::assertTrue($this->profiles()->setProfilePhoto($tag->photo, $member));

        $this->privacy()->hide(
            $member,
            [$tag->photo],
        );

        self::assertNull(self::getContainer()->get(ProfilePhotoRepository::class)->getProfilePhotoByLidnr(8030));
    }

    public function testUnhidingPutsThePhotoBackOnTheMembersPage(): void
    {
        $member = $this->member(8030);
        $tag = $this->tagsOf(8030)[0];

        $this->privacy()->hide(
            $member,
            [$tag->photo],
        );
        $this->privacy()->unhide(
            $member,
            [$tag->photo],
        );

        self::assertSame(
            [],
            self::getContainer()->get(HiddenPhotoRepository::class)->getHiddenPhotoIds($member),
        );
    }

    /**
     * Hiding one photo a second time does not stack rows, and hiding a photo the member is not in does nothing at all.
     */
    public function testHidingIsIdempotentAndOnlyAppliesToTheirOwnPhotos(): void
    {
        $member = $this->member(8030);
        $own = $this->tagsOf(8030)[0]->photo;

        $this->privacy()->hide(
            $member,
            [$own],
        );
        $this->privacy()->hide(
            $member,
            [$own],
        );
        $this->privacy()->hide(
            $member,
            [$this->tagsOf(8031)[0]->photo],
        );

        self::assertSame(
            [(int) $own->id => true],
            self::getContainer()->get(HiddenPhotoRepository::class)->getHiddenPhotoIds($member),
        );
    }

    /**
     * Hiding a photo that is not the profile photo leaves the profile photo alone.
     */
    public function testHidingAnotherPhotoKeepsTheProfilePhoto(): void
    {
        $member = $this->member(8030);
        $tag = $this->tagsOf(8030)[0];
        self::assertTrue($this->profiles()->setProfilePhoto($tag->photo, $member));
        $profile = self::getContainer()->get(ProfilePhotoRepository::class)->getProfilePhotoByLidnr(8030);

        $this->privacy()->hide(
            $member,
            [$this->tagsOf(8031)[0]->photo],
        );

        self::assertSame(
            $profile?->id,
            self::getContainer()->get(ProfilePhotoRepository::class)->getProfilePhotoByLidnr(8030)?->id,
        );
    }

    public function testRemovingAProfilePhotoThatIsNotThereDoesNothing(): void
    {
        $this->profiles()->removeProfilePhoto($this->member(8031));

        self::assertNull(self::getContainer()->get(ProfilePhotoRepository::class)->getProfilePhotoByLidnr(8031));
    }

    public function testAnExistingMemberCanBeTaggedAtAPoint(): void
    {
        $photo = $this->photo();

        $tag = $this->tags()->addMemberTag(
            $photo,
            8031,
            0.25,
            0.75,
        );

        self::assertInstanceOf(
            MemberTag::class,
            $tag,
        );
        self::assertSame(
            0.25,
            $tag->positionX,
        );
        self::assertSame(
            0.75,
            $tag->positionY,
        );
    }

    public function testAMemberWhoDoesNotExistCannotBeTagged(): void
    {
        self::assertNull($this->tags()->addMemberTag($this->photo(), 999999, null, null));
    }

    /**
     * A member who asked not to be tagged anywhere stays off the photo even though they exist and are not on it yet.
     */
    public function testAMemberWhoOptedOutCannotBeTagged(): void
    {
        // The dinner photo is tagged with 8031 only, so 8030 is a valid subject there but for the opt-out.
        $photo = $this->tagsOf(8031)[0]->photo;

        $settings = self::getContainer()->get(UserSettingsRepository::class)->find(8030);
        if (null === $settings) {
            $settings = new UserSettings($this->user(8030));
            $this->entityManager->persist($settings);
        }

        $settings->photoTaggingOptOut = true;
        $this->entityManager->flush();

        self::assertNull($this->tags()->addMemberTag($photo, 8030, null, null));
    }

    public function testAnOrgansTagIsADataRuleOfItsOwn(): void
    {
        $photo = $this->photo();
        $organId = (int) self::getContainer()->get(OrganRepository::class)->findActive()[0]->id;

        $tag = $this->tags()->addOrganTag(
            $photo,
            $organId,
            null,
            null,
        );

        self::assertNotNull($tag);
        // Tagging the same organ twice is refused rather than turning into a duplicate row.
        self::assertNull($this->tags()->addOrganTag($photo, $organId, null, null));
    }

    public function testAnOrganThatIsNotActiveCannotBeTagged(): void
    {
        self::assertNull($this->tags()->addOrganTag($this->photo(), 999999, null, null));
    }

    public function testRemovingATagRemovesTheRow(): void
    {
        $tag = $this->tagsOf(8031)[0];
        $id = (int) $tag->id;

        $this->tags()->removeTag($tag);

        self::assertNull($this->entityManager->getRepository(MemberTag::class)->find($id));
    }

    private function profiles(): ProfilePhotoService
    {
        return self::getContainer()->get(ProfilePhotoService::class);
    }

    private function tags(): TagService
    {
        return self::getContainer()->get(TagService::class);
    }

    private function privacy(): PhotoPrivacyService
    {
        return self::getContainer()->get(PhotoPrivacyService::class);
    }

    /**
     * @return list<MemberTag>
     */
    private function tagsOf(int $lidnr): array
    {
        $tags = self::getContainer()->get(MemberTagRepository::class)->getTagsByLidnr($lidnr);
        self::assertNotEmpty($tags);

        return array_values($tags);
    }

    /**
     * A Trip photo the other member is not tagged in.
     */
    private function photo(): Photo
    {
        $album = self::getContainer()->get(AlbumRepository::class)->findOneBy(['name' => 'Trip 2024']);
        self::assertNotNull($album);

        foreach (self::getContainer()->get(PhotoRepository::class)->getAlbumPhotos($album) as $photo) {
            if (
                null === self::getContainer()->get(MemberTagRepository::class)->findTag(
                    (int) $photo->id,
                    8031,
                )
                && null === self::getContainer()->get(HiddenPhotoRepository::class)->findByMemberAndPhoto(
                    $this->member(8031),
                    $photo,
                )
            ) {
                return $photo;
            }
        }

        self::fail('The seed is expected to contain a photo 8031 is not tagged in.');
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function member(int $lidnr): Member
    {
        $member = $this->entityManager->getRepository(Member::class)->find($lidnr);
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }
}
