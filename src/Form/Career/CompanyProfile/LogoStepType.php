<?php

declare(strict_types=1);

namespace App\Form\Career\CompanyProfile;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\NotNull;

use function Symfony\Component\Translation\t;

/**
 * Unmapped: the controller stores the upload and puts the path on the revision. Asked for last rather than alongside
 * the name, so the file never has to be carried between requests. `has_*` says whether one is already on file, which
 * is what decides whether it is required.
 *
 * @extends AbstractType<CompanyProfileData>
 */
class LogoStepType extends AbstractType
{
    private const string MAXIMUM_SIZE = '8M';

    private const array MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const int SQUARE_MINIMUM = 320;

    private const int BANNER_MINIMUM_WIDTH = 640;

    private const float SQUARE_MINIMUM_RATIO = 0.9;

    private const float SQUARE_MAXIMUM_RATIO = 1.1;

    private const float BANNER_MINIMUM_RATIO = 1.5;

    private const float BANNER_MAXIMUM_RATIO = 6.0;

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder->add(
            'squareLogoFile',
            FileType::class,
            [
                'label' => t('Square logo'),
                'help' => t(
                    'Your mark on its own, square, at least %size% by %size% pixels.',
                    ['%size%' => self::SQUARE_MINIMUM],
                ),
                'required' => true !== $options['has_square_logo'],
                'mapped' => false,
                'constraints' => self::logoConstraints(
                    true === $options['has_square_logo'],
                    new Image(
                        maxSize: self::MAXIMUM_SIZE,
                        mimeTypes: self::MIME_TYPES,
                        mimeTypesMessage: 'Upload a JPEG, PNG or WebP image.',
                        minWidth: self::SQUARE_MINIMUM,
                        minHeight: self::SQUARE_MINIMUM,
                        maxRatio: self::SQUARE_MAXIMUM_RATIO,
                        minRatio: self::SQUARE_MINIMUM_RATIO,
                    ),
                ),
            ],
        );

        $builder->add(
            'bannerLogoFile',
            FileType::class,
            [
                'label' => t('Banner logo'),
                'help' => t(
                    'Your mark and name side by side, at least %width% pixels wide and wider than it is tall.',
                    ['%width%' => self::BANNER_MINIMUM_WIDTH],
                ),
                'required' => true !== $options['has_banner_logo'],
                'mapped' => false,
                'constraints' => self::logoConstraints(
                    true === $options['has_banner_logo'],
                    new Image(
                        maxSize: self::MAXIMUM_SIZE,
                        mimeTypes: self::MIME_TYPES,
                        mimeTypesMessage: 'Upload a JPEG, PNG or WebP image.',
                        minWidth: self::BANNER_MINIMUM_WIDTH,
                        maxRatio: self::BANNER_MAXIMUM_RATIO,
                        minRatio: self::BANNER_MINIMUM_RATIO,
                    ),
                ),
            ],
        );
    }

    /**
     * @return list<Constraint>
     */
    private static function logoConstraints(
        bool $stored,
        Image $image,
    ): array {
        if ($stored) {
            return [$image];
        }

        return [
            new NotNull(message: 'Choose an image to upload.'),
            $image,
        ];
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            'has_square_logo' => false,
            'has_banner_logo' => false,
        ]);

        $resolver->setAllowedTypes(
            'has_square_logo',
            'bool',
        );

        $resolver->setAllowedTypes(
            'has_banner_logo',
            'bool',
        );
    }
}
