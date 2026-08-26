<?php

declare(strict_types=1);

namespace App\Form\Database\Registration;

use App\Entity\Database\Enums\PostalRegions;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<RegistrationData>
 */
class AddressStepType extends AbstractType
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
                'street',
                TextType::class,
                ['label' => t('Street')],
            )
            ->add(
                'number',
                TextType::class,
                ['label' => t('House Number')],
            )
            ->add(
                'postalCode',
                TextType::class,
                ['label' => t('Postal Code')],
            )
            ->add(
                'city',
                TextType::class,
                ['label' => t('City')],
            )
            ->add(
                'country',
                EnumType::class,
                [
                    'label' => t('Postal Region'),
                    'class' => PostalRegions::class,
                    'placeholder' => t('Select Postal Region'),
                    'invalid_message' => 'Select an existing postal region.',
                ],
            )
            ->add(
                'phone',
                TextType::class,
                [
                    'label' => t('Phone Number'),
                    'required' => false,
                    'empty_data' => '',
                ],
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true]);
    }
}
