<?php

declare(strict_types=1);

namespace App\Form\Database\Registration;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<RegistrationData>
 */
class ReviewStepType extends AbstractType
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
                'agreed',
                CheckboxType::class,
                [
                    // phpcs:ignore -- user-visible strings should not be split
                    'label' => t('I hereby declare to have filled in the form truthfully and agree to be a member of Study Association GEWIS. I am familiar with the contents of the Articles of Association and Internal Regulations. I hereby give also Gemeenschap van Wiskunde en Informatica Studenten (GEWIS) permission to process my personal data according to its Privacy Policy.'),
                ],
            )
            ->add(
                'agreedStripe',
                CheckboxType::class,
                [
                    // phpcs:ignore -- user-visible strings should not be split
                    'label' => t('I hereby authorise Stripe to process my personal data according to its privacy policy to pay the one-time membership fee.'),
                ],
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true]);
    }
}
