<?php

declare(strict_types=1);

namespace App\Form\User\ExternalApp;

use App\Entity\User\Enums\ExternalAppSignature;
use App\Entity\User\Enums\ExternalAppTokenDelivery;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<ExternalAppData>
 */
final class SigningStepType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add(
                'signature',
                EnumType::class,
                [
                    'class' => ExternalAppSignature::class,
                    'label' => t('Signing algorithm'),
                    'help' => t(
                        'Pick the strongest algorithm the application supports. Modern profiles are verified through the JWKS endpoint.', // phpcs:ignore Generic.Files.LineLength.TooLong -- user-visible strings should not be split
                    ),
                ],
            )
            ->add(
                'tokenDelivery',
                EnumType::class,
                [
                    'class' => ExternalAppTokenDelivery::class,
                    'label' => t('Token delivery'),
                    'help' => t('Modern applications require the URL fragment.'),
                ],
            )
            ->add(
                'secret',
                TextType::class,
                [
                    'label' => t('Secret'),
                    'help' => t(
                        'Only used by the HS512 shared-secret profile. Share it only with the application, and rotate it yearly.', // phpcs:ignore Generic.Files.LineLength.TooLong -- user-visible strings should not be split
                    ),
                    'required' => false,
                ],
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true]);
    }
}
