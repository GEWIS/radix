<?php

declare(strict_types=1);

namespace App\Form\Decision\OrganPage;

use App\Form\Application\CropRectangleType;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

use function intval;
use function round;
use function Symfony\Component\Translation\t;

/**
 * Unmapped: the controller stores the upload and puts the path on the revision, because only it can talk to storage.
 * Asked for on the last step, so the file and its crop never have to be carried between requests.
 *
 * @extends AbstractType<OrganPageData>
 */
class ImagesStepType extends AbstractType
{
    private const string MAXIMUM_SIZE = '8M';

    private const array MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** What the file picker offers, kept in step with {@see self::MIME_TYPES}. */
    private const string ACCEPT = 'image/jpeg,image/png,image/webp';

    /** A banner runs the width of the page, so anything narrower than this is visibly soft. */
    public const int BANNER_MINIMUM_WIDTH = 1280;

    /** A logo is shown small, but the frame takes only a share of the upload, so it still needs some room. */
    public const int LOGO_MINIMUM_WIDTH = 640;

    /** The shape each image is cut to, which is also what the crop picker holds itself to. */
    public const float BANNER_RATIO = 4.0;

    public const float LOGO_RATIO = 16 / 9;

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $bannerMinimumHeight = self::minimumHeight(
            self::BANNER_MINIMUM_WIDTH,
            self::BANNER_RATIO,
        );
        $logoMinimumHeight = self::minimumHeight(
            self::LOGO_MINIMUM_WIDTH,
            self::LOGO_RATIO,
        );

        $builder->add(
            'bannerFile',
            FileType::class,
            [
                'label' => t('Page banner'),
                'help' => t(
                    'The wide strip across the top of your own page, at least %width% by %height% pixels.',
                    [
                        '%width%' => self::BANNER_MINIMUM_WIDTH,
                        '%height%' => $bannerMinimumHeight,
                    ],
                ),
                'required' => false,
                'mapped' => false,
                // The picker offers only what the constraint below would accept, so a file that could never be stored
                // is not offered in the first place, and the width the constraint wants travels along so the frame can
                // turn away an image it would refuse. The constraints still decide: both attributes are conveniences.
                'attr' => [
                    'accept' => self::ACCEPT,
                    'data-minimum-width' => self::BANNER_MINIMUM_WIDTH,
                    'data-minimum-height' => $bannerMinimumHeight,
                ],
                'constraints' => [
                    new Image(
                        maxSize: self::MAXIMUM_SIZE,
                        mimeTypes: self::MIME_TYPES,
                        mimeTypesMessage: 'Upload a JPEG, PNG or WebP image.',
                        minWidth: self::BANNER_MINIMUM_WIDTH,
                        minHeight: $bannerMinimumHeight,
                    ),
                ],
            ],
        );

        $builder->add(
            'logoFile',
            FileType::class,
            [
                'label' => t('Logo'),
                'help' => t(
                    'What your body is recognised by on an overview card, at least %width% by %height% pixels.',
                    [
                        '%width%' => self::LOGO_MINIMUM_WIDTH,
                        '%height%' => $logoMinimumHeight,
                    ],
                ),
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'accept' => self::ACCEPT,
                    'data-minimum-width' => self::LOGO_MINIMUM_WIDTH,
                    'data-minimum-height' => $logoMinimumHeight,
                ],
                'constraints' => [
                    new Image(
                        maxSize: self::MAXIMUM_SIZE,
                        mimeTypes: self::MIME_TYPES,
                        mimeTypesMessage: 'Upload a JPEG, PNG or WebP image.',
                        minWidth: self::LOGO_MINIMUM_WIDTH,
                        minHeight: $logoMinimumHeight,
                    ),
                ],
            ],
        );

        // What the crop picker writes back: the chosen rectangle as fractions of whichever rendition it was shown,
        // which is what makes it independent of that rendition's size.
        //
        // These start out empty, and only the picker ever fills them. The crop that is in force reaches the picker
        // beside the frame instead of through here: a rectangle that is put in by hand is submitted again even when
        // nothing drew a frame, and would then be cut out of an image it was never chosen on.
        foreach (
            [
                'bannerCropData',
                'logoCropData',
            ] as $field
        ) {
            $builder->add(
                $field,
                CropRectangleType::class,
                [
                    'label' => false,
                    'mapped' => false,
                ],
            );
        }
    }

    /**
     * The height that follows from a minimum width and the shape the image is cut to. An upload that is wide enough but
     * too flat holds no rectangle of that shape and that width, so asking for the width alone would let through a file
     * that has no usable crop in it at all.
     */
    private static function minimumHeight(
        int $width,
        float $ratio,
    ): int {
        return intval(round($width / $ratio));
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true]);
    }
}
