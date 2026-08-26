<?php

declare(strict_types=1);

namespace App\Form\Frontpage\Page;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<PageData>
 */
class ContentStepType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        foreach (
            [
                'titleNL',
                'titleEN',
            ] as $field
        ) {
            $builder->add(
                $field,
                TextType::class,
                [
                    'label' => t('Title'),
                    'required' => false,
                ],
            );
        }

        foreach (
            [
                'contentNL',
                'contentEN',
            ] as $field
        ) {
            $builder->add(
                $field,
                TextareaType::class,
                [
                    'label' => t('Content'),
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
