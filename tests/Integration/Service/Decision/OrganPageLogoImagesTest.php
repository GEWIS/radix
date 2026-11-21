<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganInformation;
use App\Entity\Decision\OrganInformationRevision;
use App\Service\Application\FileStorage;
use App\Service\Decision\OrganPageService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function getimagesizefromstring;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function is_file;
use function register_shutdown_function;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The logo half of putting images on a draft, which the banner tests in {@see OrganPageServiceTest} leave at its
 * seeded value: an upload replaces both the source and the served path, and a frame afterwards cuts the served image
 * out of that source.
 */
final class OrganPageLogoImagesTest extends DatabaseTestCase
{
    public function testUploadingALogoPutsItOnTheDraft(): void
    {
        $draft = $this->draft();
        $bannerBefore = $draft->bannerSource;

        $accepted = $this->service()->applyImages(
            $draft,
            null,
            $this->imageFile(
                900,
                900,
            ),
            null,
            null,
        );

        self::assertTrue($accepted);
        self::assertNotNull($draft->logoSource);
        self::assertSame(
            $draft->logoSource,
            $draft->logoPath,
        );
        self::assertNull($draft->logoCrop);
        self::assertTrue($this->storage()->exists($draft->logoSource));
        // The banner is not part of the same upload and keeps what it had.
        self::assertSame(
            $bannerBefore,
            $draft->bannerSource,
        );
    }

    public function testALogoCropReplacesTheServedImageAndKeepsTheSource(): void
    {
        $draft = $this->draft();

        $this->service()->applyImages(
            $draft,
            null,
            $this->imageFile(
                900,
                900,
            ),
            null,
            null,
        );
        $source = $draft->logoSource;
        self::assertNotNull($source);

        $accepted = $this->service()->applyImages(
            $draft,
            null,
            null,
            null,
            [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 1.0,
                'height' => 1.0,
            ],
        );

        self::assertTrue($accepted);
        self::assertSame(
            $source,
            $draft->logoSource,
        );
        self::assertNotNull($draft->logoPath);
        self::assertNotSame(
            $source,
            $draft->logoPath,
        );
        self::assertSame(
            [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 1.0,
                'height' => 1.0,
            ],
            $draft->logoCrop,
        );
        self::assertSame(
            [
                900,
                900,
            ],
            $this->dimensions($draft->logoPath),
        );
    }

    /**
     * A frame that cannot be cut is refused, but the text and the other images are still saved.
     */
    public function testALogoFrameThatCannotBeAppliedIsRefused(): void
    {
        $draft = $this->draft();

        $this->service()->applyImages(
            $draft,
            null,
            $this->imageFile(
                900,
                900,
            ),
            null,
            null,
        );
        $path = $draft->logoPath;

        $accepted = $this->service()->applyImages(
            $draft,
            null,
            null,
            null,
            [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 0.0,
                'height' => 1.0,
            ],
        );

        self::assertFalse($accepted);
        self::assertSame(
            $path,
            $draft->logoPath,
        );
    }

    /**
     * A refused logo is reported and leaves the previous one in place, exactly as a refused banner does.
     */
    public function testAnInvalidLogoIsRefusedAndLeavesThePreviousImage(): void
    {
        $draft = $this->draft();
        $sourceBefore = $draft->logoSource;
        $pathBefore = $draft->logoPath;

        $accepted = $this->service()->applyImages(
            $draft,
            null,
            $this->textFile(),
            null,
            null,
        );

        self::assertFalse($accepted);
        self::assertSame(
            $sourceBefore,
            $draft->logoSource,
        );
        self::assertSame(
            $pathBefore,
            $draft->logoPath,
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

    private function draft(): OrganInformationRevision
    {
        $organ = $this->entityManager->getRepository(Organ::class)->findOneBy([
            'abbr' => 'GETÉST',
            'abrogationDate' => null,
        ]);
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );
        $page = $organ->organInformation;
        self::assertInstanceOf(
            OrganInformation::class,
            $page,
        );

        $draft = $page->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $draft,
        );

        return $draft;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function imageFile(
        int $width,
        int $height,
    ): UploadedFile {
        $path = tempnam(
            sys_get_temp_dir(),
            'gewisweb-organ-logo-test',
        );
        self::assertIsString($path);

        $image = imagecreatetruecolor(
            $width,
            $height,
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
            $width,
            $height,
            $colour,
        );
        imagejpeg(
            $image,
            $path,
        );

        register_shutdown_function(static function () use ($path): void {
            if (!is_file($path)) {
                return;
            }

            unlink($path);
        });

        return new UploadedFile(
            $path,
            'logo.jpg',
            'image/jpeg',
            null,
            true,
        );
    }

    private function textFile(): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'gewisweb-organ-logo-text',
        );
        self::assertIsString($path);
        file_put_contents(
            $path,
            'This is not an image.',
        );

        register_shutdown_function(static function () use ($path): void {
            if (!is_file($path)) {
                return;
            }

            unlink($path);
        });

        return new UploadedFile(
            $path,
            'logo.txt',
            'text/plain',
            null,
            true,
        );
    }

    private function storage(): FileStorage
    {
        return self::getContainer()->get(FileStorage::class);
    }

    private function service(): OrganPageService
    {
        return self::getContainer()->get(OrganPageService::class);
    }
}
