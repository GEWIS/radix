<?php

declare(strict_types=1);

namespace App\Form\Decision\OrganPage;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * The two descriptions, written per language. The language checkboxes drive the `localised-fields` Stimulus
 * controller and are mapped, because the rule on the data object is read against them.
 *
 * @extends AbstractType<OrganPageData>
 */
class PageStepType extends AbstractType
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
                'shortDescriptionNL',
                'shortDescriptionEN',
            ] as $field
        ) {
            $builder->add(
                $field,
                TextType::class,
                [
                    'label' => t('Short description'),
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
