<?php

declare(strict_types=1);

namespace App\DataFixtures\Career;

use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Career\Company;
use App\Entity\Career\Enums\CompanyBannerFormats;
use App\Service\Application\FileStorage;
use App\Service\Application\ImageManagerProvider;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Geometry\Point;
use Intervention\Image\Geometry\Rectangle;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function abs;
use function count;
use function crc32;
use function file_put_contents;
use function intdiv;
use function max;
use function mb_strtoupper;
use function mb_substr;
use function min;
use function sprintf;
use function strlen;
use function strval;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Draws the artwork the career fixtures need, banner-package images and company logos, and stores it the way an upload
 * would, so the career pages serve real files instead of pointing at paths nobody ever wrote anything to.
 *
 * Images are generated rather than committed to the repository, as the photo fixtures do. Only the original is stored:
 * the renditions the pages ask for are generated the first time one is requested.
 *
 * A banner is drawn at twice the box its format is shown in, which is what a company hands over when it wants its
 * artwork to survive a dense screen, and means both the ordinary and the retina rendition are real downscales.
 */
final readonly class CompanyImageGenerator
{
    /** Below this a caption stops being readable at all, however narrow the box it was given. */
    private const int MINIMUM_FONT_SIZE = 12;

    private const int SQUARE_LOGO_SIZE = 512;

    private const int BANNER_LOGO_HEIGHT = 320;

    /** Background colours, picked per company so two of them are told apart at a glance. */
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
        [
            55,
            71,
            79,
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
     * A banner carrying the company name and its slogan, which is what the format describes real artwork as. The
     * slogan is also what tells two banners for the same company apart, so a banner proposed alongside a new slogan
     * does not collapse onto the one it is meant to replace: stored files are content-addressed, and identical bytes
     * are one file.
     */
    public function storeBanner(
        Company $company,
        CompanyBannerFormats $format,
        string $slogan,
    ): string {
        $width = $format->width() * 2;
        $height = $format->height() * 2;
        $padding = intdiv(
            $height,
            10,
        );

        $image = $this->createCanvas(
            $width,
            $height,
        );
        $background = $this->colorFor($company->getName() . $slogan);
        $this->fill(
            $image,
            $background,
            0,
            0,
            $width,
            $height,
        );

        // A square of flat colour on the left, where finished artwork would carry the company's own mark.
        $accent = $this->lighten($background);
        $this->fill(
            $image,
            $accent,
            0,
            0,
            $height,
            $height,
        );
        $this->drawText(
            $image,
            $this->monogram($company->getName()),
            intdiv(
                $height,
                2,
            ),
            intdiv(
                $height,
                2,
            ),
            intdiv(
                $height,
                2,
            ),
            intdiv(
                $height,
                2,
            ),
        );

        $columnWidth = $width - $height - 2 * $padding;
        $columnCentre = $height + $padding + intdiv(
            $columnWidth,
            2,
        );
        $this->drawText(
            $image,
            $company->getName(),
            $columnCentre,
            intdiv(
                $height * 2,
                5,
            ),
            $columnWidth,
            intdiv(
                $height,
                3,
            ),
        );
        $this->drawText(
            $image,
            $slogan,
            $columnCentre,
            intdiv(
                $height * 7,
                10,
            ),
            $columnWidth,
            intdiv(
                $height,
                6,
            ),
        );

        return $this->store(
            $image,
            $company,
        );
    }

    public function storeSquareLogo(Company $company): string
    {
        $size = self::SQUARE_LOGO_SIZE;
        $image = $this->createCanvas(
            $size,
            $size,
        );
        $background = $this->colorFor($company->getName());
        $this->fill(
            $image,
            $background,
            0,
            0,
            $size,
            $size,
        );

        $this->drawText(
            $image,
            $this->monogram($company->getName()),
            intdiv(
                $size,
                2,
            ),
            intdiv(
                $size,
                2,
            ),
            intdiv(
                $size * 3,
                5,
            ),
            intdiv(
                $size * 3,
                5,
            ),
        );

        return $this->store(
            $image,
            $company,
        );
    }

    public function storeBannerLogo(Company $company): string
    {
        $height = self::BANNER_LOGO_HEIGHT;
        $width = $height * (2 + abs(crc32($company->getName())) % 3);
        $image = $this->createCanvas(
            $width,
            $height,
        );
        $background = $this->colorFor($company->getName());
        $this->fill(
            $image,
            $background,
            0,
            0,
            $width,
            $height,
        );

        $accent = $this->lighten($background);
        $this->fill(
            $image,
            $accent,
            0,
            0,
            $height,
            $height,
        );
        $this->drawText(
            $image,
            $this->monogram($company->getName()),
            intdiv(
                $height,
                2,
            ),
            intdiv(
                $height,
                2,
            ),
            intdiv(
                $height * 3,
                5,
            ),
            intdiv(
                $height * 3,
                5,
            ),
        );

        $columnWidth = $width - $height;
        $this->drawText(
            $image,
            $company->getName(),
            $height + intdiv(
                $columnWidth,
                2,
            ),
            intdiv(
                $height,
                2,
            ),
            $columnWidth - intdiv(
                $height,
                4,
            ),
            intdiv(
                $height,
                3,
            ),
        );

        return $this->store(
            $image,
            $company,
        );
    }

    /**
     * Draws a caption centred on a point, as large as the given box takes.
     */
    private function drawText(
        ImageInterface $target,
        string $text,
        int $centreX,
        int $centreY,
        int $boxWidth,
        int $boxHeight,
    ): void {
        // DejaVu Sans Bold advances about six tenths of its size per character, which is close enough to keep a
        // caption inside the box it was given.
        $size = max(
            self::MINIMUM_FONT_SIZE,
            min(
                $boxHeight,
                intdiv(
                    $boxWidth * 10,
                    max(
                        1,
                        strlen($text),
                    ) * 6,
                ),
            ),
        );

        $target->text(
            $text,
            $centreX,
            $centreY + intdiv(
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
    }

    /**
     * Writes the drawing out and stores it against the company, which is what scopes the stored path, so the company
     * must already have an id by the time this is called.
     */
    private function store(
        ImageInterface $image,
        Company $company,
    ): string {
        $temporaryFile = tempnam(
            sys_get_temp_dir(),
            'fixture-company-image-',
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
                StorageNamespace::CompanyImage,
                $temporaryFile,
                strval($company->getId()),
            )->path;
        } finally {
            unlink($temporaryFile);
        }
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function createCanvas(
        int $width,
        int $height,
    ): ImageInterface {
        return $this->imageManagerProvider->create()->createImage(
            $width,
            $height,
        );
    }

    /**
     * @param array{int<0, 255>, int<0, 255>, int<0, 255>} $color
     */
    private function fill(
        ImageInterface $image,
        array $color,
        int $left,
        int $top,
        int $right,
        int $bottom,
    ): void {
        $rectangle = new Rectangle(
            max(
                1,
                $right - $left,
            ),
            max(
                1,
                $bottom - $top,
            ),
            new Point(
                $left,
                $top,
            ),
        );
        $rectangle->setBackgroundColor($this->hex($color));

        $image->drawRectangle($rectangle);
    }

    /**
     * @param array{int<0, 255>, int<0, 255>, int<0, 255>} $color
     */
    private function hex(array $color): string
    {
        [
            $red,
            $green,
            $blue,
        ] = $color;

        return sprintf(
            '#%02x%02x%02x',
            $red,
            $green,
            $blue,
        );
    }

    /**
     * The colour an image is drawn in, from the text it is drawn for, so a company keeps the same look everywhere.
     *
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private function colorFor(string $seed): array
    {
        return self::PALETTE[abs(crc32($seed)) % count(self::PALETTE)];
    }

    /**
     * @param array{int<0, 255>, int<0, 255>, int<0, 255>} $color
     *
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private function lighten(array $color): array
    {
        [
            $red,
            $green,
            $blue,
        ] = $color;

        return [
            min(
                255,
                $red + 45,
            ),
            min(
                255,
                $green + 45,
            ),
            min(
                255,
                $blue + 45,
            ),
        ];
    }

    private function monogram(string $name): string
    {
        return mb_strtoupper(mb_substr(
            $name,
            0,
            1,
        ));
    }
}
