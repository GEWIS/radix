<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Application\EditLock;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganInformation;
use App\Entity\Decision\OrganInformationRevision;
use App\Entity\User\User;
use App\Service\Application\EditLockService;
use App\Service\Application\FileStorage;
use App\Service\Decision\OrganPageService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function count;
use function file_put_contents;
use function getimagesizefromstring;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Writing a body's page: the page and its first draft are created together, and images are put on the draft in the
 * state the crop picker left them. A refused upload leaves the image that was already there.
 */
final class OrganPageServiceTest extends DatabaseTestCase
{
    public function testCreatingAPageAlsoStartsItsFirstDraft(): void
    {
        $organ = $this->organ('ATT');
        self::assertNull($organ->organInformation);

        $page = $this->service()->createPage(
            $organ,
            $this->user(),
        );

        self::assertNotNull($page->id);
        self::assertSame(
            $organ,
            $page->organ,
        );
        self::assertSame(
            $page,
            $organ->organInformation,
        );
        self::assertNull($page->getLiveRevision());
        self::assertCount(
            1,
            $page->getRevisions(),
        );

        $draft = $page->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $draft,
        );
        self::assertSame(
            RevisionStatus::Draft,
            $draft->getStatus(),
        );
        self::assertSame(
            1,
            $draft->getRevisionNumber(),
        );
        self::assertSame(
            $this->user()->member->lidnr,
            $draft->getAuthor()?->lidnr,
        );
    }

    public function testStartingAnotherDraftMakesItTheCurrentRevision(): void
    {
        $page = $this->page('KCC');
        $live = $page->getLiveRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $live,
        );
        $before = count($page->getRevisions());

        $this->service()->startFirstDraft(
            $page,
            $this->user(),
        );

        $draft = $page->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $draft,
        );
        self::assertNotSame(
            $live,
            $draft,
        );
        self::assertSame(
            RevisionStatus::Draft,
            $draft->getStatus(),
        );
        self::assertCount(
            $before + 1,
            $page->getRevisions(),
        );
        self::assertSame(
            $this->user()->member->lidnr,
            $draft->getAuthor()?->lidnr,
        );
    }

    /**
     * Saving and releasing the lock are one operation: a save that leaves the lock behind locks the author out of their
     * own draft.
     */
    public function testSavingADraftRecordsTheEditorAndReleasesTheLock(): void
    {
        $user = $this->user();
        $page = $this->page('GETÉST');
        $draft = $page->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $draft,
        );

        self::assertNotNull($this->locks()->acquire(
            $page,
            $user,
        ));
        self::assertNotNull($this->lock($page));

        $this->service()->saveDraft(
            $page,
            $draft,
            $user,
        );

        self::assertSame(
            $user->member->lidnr,
            $draft->getAuthor()?->lidnr,
        );
        self::assertSame(
            $user->lidnr,
            $draft->lastEditedBy?->lidnr,
        );
        self::assertNull($this->lock($page));
    }

    public function testUploadingABannerPutsItOnTheDraft(): void
    {
        $page = $this->page('GETÉST');
        $draft = $page->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $draft,
        );
        $logoBefore = $draft->logoSource;

        $accepted = $this->service()->applyImages(
            $draft,
            $this->imageFile(),
            null,
            null,
            null,
        );

        self::assertTrue($accepted);
        self::assertNotNull($draft->bannerSource);
        self::assertSame(
            $draft->bannerSource,
            $draft->bannerPath,
        );
        self::assertNull($draft->bannerCrop);
        self::assertTrue($this->storage()->exists($draft->bannerSource));
        self::assertSame(
            [
                1600,
                400,
            ],
            $this->dimensions($draft->bannerSource),
        );
        self::assertSame(
            $logoBefore,
            $draft->logoSource,
        );
    }

    public function testACropReplacesTheServedImageAndKeepsTheSource(): void
    {
        $page = $this->page('GETÉST');
        $draft = $page->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $draft,
        );

        $this->service()->applyImages(
            $draft,
            $this->imageFile(),
            null,
            null,
            null,
        );
        $source = $draft->bannerSource;
        self::assertNotNull($source);

        $accepted = $this->service()->applyImages(
            $draft,
            null,
            null,
            [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 1.0,
                'height' => 1.0,
            ],
            null,
        );

        self::assertTrue($accepted);
        self::assertSame(
            $source,
            $draft->bannerSource,
        );
        self::assertNotSame(
            $source,
            $draft->bannerPath,
        );
        self::assertNotNull($draft->bannerPath);
        self::assertTrue($this->storage()->exists($draft->bannerPath));
        self::assertSame(
            [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 1.0,
                'height' => 1.0,
            ],
            $draft->bannerCrop,
        );
        self::assertSame(
            [
                1600,
                400,
            ],
            $this->dimensions($draft->bannerPath),
        );
    }

    /**
     * A refused image leaves the previous one in place rather than costing the author their text.
     */
    public function testAnInvalidUploadIsRefusedAndLeavesThePreviousImage(): void
    {
        $page = $this->page('GETÉST');
        $draft = $page->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $draft,
        );
        $sourceBefore = $draft->bannerSource;
        $pathBefore = $draft->bannerPath;

        $accepted = $this->service()->applyImages(
            $draft,
            $this->textFile(),
            null,
            null,
            null,
        );

        self::assertFalse($accepted);
        self::assertSame(
            $sourceBefore,
            $draft->bannerSource,
        );
        self::assertSame(
            $pathBefore,
            $draft->bannerPath,
        );
    }

    public function testAFrameThatCannotBeAppliedIsRefused(): void
    {
        $page = $this->page('GETÉST');
        $draft = $page->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $draft,
        );
        $pathBefore = $draft->bannerPath;

        $accepted = $this->service()->applyImages(
            $draft,
            null,
            null,
            [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 0.0,
                'height' => 1.0,
            ],
            null,
        );

        self::assertFalse($accepted);
        self::assertSame(
            $pathBefore,
            $draft->bannerPath,
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(string $path): array
    {
        $info = getimagesizefromstring($this->storage()->read($path));
        self::assertIsArray($info);

        return [
            $info[0],
            $info[1],
        ];
    }

    private function lock(OrganInformation $page): ?EditLock
    {
        self::assertNotNull($page->id);

        return $this->entityManager->getRepository(EditLock::class)->findOneBy([
            'resourceId' => $page->getResourceId(),
            'resourceKey' => $page->id,
        ]);
    }

    private function page(string $abbr): OrganInformation
    {
        $page = $this->organ($abbr)->organInformation;
        self::assertInstanceOf(
            OrganInformation::class,
            $page,
        );

        return $page;
    }

    private function organ(string $abbr): Organ
    {
        $organ = $this->entityManager->getRepository(Organ::class)->findOneBy([
            'abbr' => $abbr,
            'abrogationDate' => null,
        ]);
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        return $organ;
    }

    private function user(): User
    {
        $user = $this->entityManager->find(
            User::class,
            8000,
        );
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function imageFile(): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'gewisweb-organ-page-test',
        );
        self::assertIsString($path);

        $image = imagecreatetruecolor(
            1600,
            400,
        );
        self::assertNotFalse($image);
        $colour = imagecolorallocate(
            $image,
            0x1E,
            0x7A,
            0x30,
        );
        self::assertNotFalse($colour);
        imagefilledrectangle(
            $image,
            0,
            0,
            1600,
            400,
            $colour,
        );
        imagejpeg(
            $image,
            $path,
        );

        return new UploadedFile(
            $path,
            'banner.jpg',
            'image/jpeg',
            null,
            true,
        );
    }

    private function textFile(): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'gewisweb-organ-page-test',
        );
        self::assertIsString($path);
        file_put_contents(
            $path,
            'This is not an image.',
        );

        return new UploadedFile(
            $path,
            'banner.txt',
            'text/plain',
            null,
            true,
        );
    }

    private function storage(): FileStorage
    {
        return self::getContainer()->get(FileStorage::class);
    }

    private function locks(): EditLockService
    {
        return self::getContainer()->get(EditLockService::class);
    }

    private function service(): OrganPageService
    {
        return self::getContainer()->get(OrganPageService::class);
    }
}
