<?php

declare(strict_types=1);

namespace App\Form\Database\Registration;

use App\Form\DataTransformer\LowercaseTransformer;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<RegistrationData>
 */
class PersonalStepType extends AbstractType
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
                'initials',
                TextType::class,
                ['label' => t('Initial(s)')],
            )
            ->add(
                'firstName',
                TextType::class,
                ['label' => t('First Name')],
            )
            ->add(
                'middleName',
                TextType::class,
                [
                    'label' => t('Last Name Prepositional Particle'),
                    'required' => false,
                    'empty_data' => '',
                ],
            )
            ->add(
                'lastName',
                TextType::class,
                ['label' => t('Last Name')],
            )
            ->add(
                'email',
                EmailType::class,
                ['label' => t('E-mail Address')],
            )
            ->add(
                'birth',
                DateType::class,
                [
                    'label' => t('Birthdate'),
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                ],
            );

        $builder->get('email')->addModelTransformer(new LowercaseTransformer());
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true]);
    }
}
