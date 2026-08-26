<?php

declare(strict_types=1);

namespace App\Form\Career\CompanyProfile;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * What a company says about itself, written per language. The two checkboxes drive the `localised-fields` Stimulus
 * controller and are what the rule on the data object is read against.
 *
 * @extends AbstractType<CompanyProfileData>
 */
class ProfileStepType extends AbstractType
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
                'languageDutch',
                CheckboxType::class,
                [
                    'label' => t('Dutch'),
                    'required' => false,
                ],
            )
            ->add(
                'languageEnglish',
                CheckboxType::class,
                [
                    'label' => t('English'),
                    'required' => false,
                ],
            );

        foreach (
            [
                'sloganNL' => t('Slogan'),
                'sloganEN' => t('Slogan'),
                'websiteNL' => t('Website'),
                'websiteEN' => t('Website'),
            ] as $field => $label
        ) {
            $builder->add(
                $field,
                TextType::class,
                [
                    'label' => $label,
                    'required' => false,
                ],
            );
        }

        foreach (
            [
                'descriptionNL',
                'descriptionEN',
            ] as $field
        ) {
            $builder->add(
                $field,
                TextareaType::class,
                [
                    'label' => t('Description'),
                    'required' => false,
                ],
            );
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true]);
    }
}
