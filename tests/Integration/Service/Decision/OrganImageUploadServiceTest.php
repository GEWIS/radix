<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Service\Application\FileStorage;
use App\Service\Decision\OrganImageUploadService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function count;
use function file_put_contents;
use function getimagesizefromstring;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;

/**
 * A body page's images are stored twice: the upload as it arrived, so the frame can be adjusted later, and the
 * cropped copy the page serves. The test storage is the in-memory adapter, so both can be read back and their
 * dimensions checked.
 */
final class OrganImageUploadServiceTest extends DatabaseTestCase
{
    public function testAnUploadedSourceIsStoredUnderTheOrganImages(): void
    {
        $path = $this->service()->uploadSource($this->imageFile(
            1200,
            600,
        ));

        self::assertIsString($path);
        self::assertTrue(str_contains($path, 'organs/images/'));
        self::assertTrue($this->storage()->exists($path));
        self::assertSame(
            [
                1200,
                600,
            ],
            $this->dimensions($path),
        );
    }

    public function testSomethingThatIsNotAnImageIsRefusedAndLeavesNothingBehind(): void
    {
        $before = count($this->storage()->listFiles('organs/images'));

        self::assertNull($this->service()->uploadSource($this->textFile()));
        self::assertCount(
            $before,
            $this->storage()->listFiles('organs/images'),
        );
    }

    public function testACropIsStoredAsAnImageOfItsOwn(): void
    {
        $source = $this->service()->uploadSource($this->imageFile(
            1200,
            600,
        ));
        self::assertIsString($source);

        $cropped = $this->service()->applyCrop(
            $source,
            [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 0.5,
                'height' => 1.0,
            ],
            100,
        );

        self::assertIsString($cropped);
        self::assertNotSame(
            $source,
            $cropped,
        );
        self::assertTrue($this->storage()->exists($cropped));
        self::assertSame(
            [
                600,
                600,
            ],
            $this->dimensions($cropped),
        );
    }

    public function testACropThatRunsOffTheEdgeIsPulledBackInside(): void
    {
        $source = $this->service()->uploadSource($this->imageFile(
            1200,
            600,
        ));
        self::assertIsString($source);

        $cropped = $this->service()->applyCrop(
            $source,
            [
                'x' => 1.0,
                'y' => 1.0,
                'width' => 0.5,
                'height' => 0.5,
            ],
            100,
        );

        self::assertIsString($cropped);
        self::assertSame(
            [
                600,
                300,
            ],
            $this->dimensions($cropped),
        );
    }

    /**
     * The picker applies the upload minimum itself, so this only guards hand-made requests: a frame that would cut out
     * too little is grown in shape rather than stored.
     */
    public function testACropNarrowerThanTheMinimumIsGrownInShape(): void
    {
        $source = $this->service()->uploadSource($this->imageFile(
            1200,
            600,
        ));
        self::assertIsString($source);

        $cropped = $this->service()->applyCrop(
            $source,
            [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 0.1,
                'height' => 0.5,
            ],
            600,
        );

        self::assertIsString($cropped);
        // 120 by 300 is grown by the largest factor that still fits, which is the height.
        self::assertSame(
            [
                240,
                600,
            ],
            $this->dimensions($cropped),
        );
    }

    public function testAnEmptyCropIsRefused(): void
    {
        $source = $this->service()->uploadSource($this->imageFile(
            1200,
            600,
        ));
        self::assertIsString($source);

        self::assertNull($this->service()->applyCrop(
            $source,
            [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 0.0,
                'height' => 1.0,
            ],
            100,
        ));
    }

    public function testACropOfAnUnknownSourceIsRefused(): void
    {
        self::assertNull($this->service()->applyCrop(
            'organs/images/missing.jpg',
            [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 1.0,
                'height' => 1.0,
            ],
            100,
        ));
    }

    public function testSourceWidthReportsTheOriginal(): void
    {
        $source = $this->service()->uploadSource($this->imageFile(
            1200,
            600,
        ));
        self::assertIsString($source);

        self::assertSame(
            1200,
            $this->service()->sourceWidth($source),
        );
        self::assertNull($this->service()->sourceWidth(null));
        self::assertNull($this->service()->sourceWidth('organs/images/missing.jpg'));
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
            'gewisweb-organ-image-test',
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

        return new UploadedFile(
            $path,
            'organ.jpg',
            'image/jpeg',
            null,
            true,
        );
    }

    private function textFile(): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'gewisweb-organ-image-test',
        );
        self::assertIsString($path);
        file_put_contents(
            $path,
            'This is not an image.',
        );

        return new UploadedFile(
            $path,
            'organ.txt',
            'text/plain',
            null,
            true,
        );
    }

    private function storage(): FileStorage
    {
        return self::getContainer()->get(FileStorage::class);
    }

    private function service(): OrganImageUploadService
    {
        return self::getContainer()->get(OrganImageUploadService::class);
    }
}
