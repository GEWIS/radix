<?php

declare(strict_types=1);

namespace App\DataFixtures\Decision;

use App\Entity\Application\Enums\StorageNamespace;
use App\Service\Application\FileStorage;
use App\Service\Application\ImageManagerProvider;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function abs;
use function count;
use function crc32;
use function file_put_contents;
use function intdiv;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Draws the banner and the card image the body fixtures need, so the overviews and the body pages serve real files
 * instead of pointing at paths nobody ever wrote anything to. Generated rather than committed, as the career and photo
 * fixtures do.
 *
 * Each is drawn at the shape it is shown in, since what a body stores is already cropped; only the original is written,
 * and the renditions a page asks for are generated the first time one is requested.
 */
final readonly class OrganImageGenerator
{
    private const int BANNER_WIDTH = 1600;

    private const int BANNER_HEIGHT = 400;

    private const int LOGO_WIDTH = 800;

    private const int LOGO_HEIGHT = 450;

    /** Background colours, picked per body so two of them are told apart at a glance. */
    private const array PALETTE = [
        [
            21,
            101,
            192,
        ],
        [
            46,
            125,
            50,
        ],
        [
            106,
            27,
            154,
        ],
        [
            191,
            54,
            12,
        ],
        [
            0,
            131,
            143,
        ],
    ];

    public function __construct(
        private FileStorage $fileStorage,
        private ImageManagerProvider $imageManagerProvider,
        #[Autowire('%app.font%')]
        private string $fontPath,
    ) {
    }

    /**
     * The banner across the top of a body's page, at the wide shape that suits one.
     */
    public function storeBanner(string $abbr): string
    {
        return $this->draw(
            $abbr,
            self::BANNER_WIDTH,
            self::BANNER_HEIGHT,
        );
    }

    /**
     * The image a body is shown by on an overview.
     */
    public function storeLogo(string $abbr): string
    {
        return $this->draw(
            $abbr,
            self::LOGO_WIDTH,
            self::LOGO_HEIGHT,
        );
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function draw(
        string $abbr,
        int $width,
        int $height,
    ): string {
        $size = intdiv(
            $height,
            4,
        );

        $image = $this->imageManagerProvider->create()
            ->createImage(
                $width,
                $height,
            )
            ->fill($this->background($abbr));

        $image->text(
            $abbr,
            intdiv(
                $width,
                2,
            ),
            // The baseline, since this build of the font factory has no vertical alignment of its own.
            intdiv(
                $height,
                2,
            ) + intdiv(
                $size,
                3,
            ),
            function (FontFactory $font) use ($size): void {
                $font->filename($this->fontPath);
                $font->size($size);
                $font->color('#ffffff');
                $font->align('center');
            },
        );

        return $this->store($image);
    }

    private function background(string $abbr): string
    {
        [
            $red,
            $green,
            $blue,
        ] = self::PALETTE[abs(crc32($abbr)) % count(self::PALETTE)];

        return sprintf(
            '#%02x%02x%02x',
            $red,
            $green,
            $blue,
        );
    }

    private function store(ImageInterface $image): string
    {
        $temporaryFile = tempnam(
            sys_get_temp_dir(),
            'fixture-organ-image-',
        );
        if (false === $temporaryFile) {
            throw new RuntimeException('Could not create a temporary file for a fixture image.');
        }

        file_put_contents(
            $temporaryFile,
            $image->encode(new PngEncoder())->toString(),
        );

        try {
            return $this->fileStorage->store(
                StorageNamespace::OrganImage,
                $temporaryFile,
            )->path;
        } finally {
            unlink($temporaryFile);
        }
    }
}
