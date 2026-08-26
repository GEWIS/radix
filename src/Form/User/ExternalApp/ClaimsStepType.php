<?php

declare(strict_types=1);

namespace App\Form\User\ExternalApp;

use App\Entity\User\Enums\JWTClaims;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<ExternalAppData>
 */
final class ClaimsStepType extends AbstractType
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
                'claims',
                EnumType::class,
                [
                    'class' => JWTClaims::class,
                    'label' => t('Claims'),
                    'help' => t('The information the token carries about the member.'),
                    'multiple' => true,
                    'expanded' => true,
                    'required' => false,
                ],
            )
            ->add(
                'enabled',
                CheckboxType::class,
                [
                    'label' => t('Enabled'),
                    'help' => t('Disabled applications can no longer authenticate.'),
                    'required' => false,
                ],
            )
            ->add(
                'expiresAt',
                DateTimeType::class,
                [
                    'label' => t('Expires at'),
                    'help' => t('After this the application can no longer authenticate. Leave empty for no expiry.'),
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
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
