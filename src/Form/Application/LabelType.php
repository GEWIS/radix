<?php

declare(strict_types=1);

namespace App\Form\Application;

use App\Entity\Application\LabelInterface;
use App\Validator\Application\NotLikeCategory;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatableInterface;

use function is_subclass_of;
use function Symfony\Component\Translation\t;

/**
 * A label in both languages, for every domain that has labels. The label class and the categories a label may not be
 * similar to are options; the class its name is stored in comes from the label itself.
 *
 * Both translations are required. A label is one or two words shown on everything tagged with it, unlike revisable
 * content, where a language can be switched off.
 *
 * @extends AbstractType<LabelInterface>
 */
class LabelType extends AbstractType
{
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        /** @var class-string<LabelInterface> $labelClass */
        $labelClass = $options['data_class'];

        $notLikeCategory = new NotLikeCategory(
            categories: $options['categories'],
            message: 'This name is too similar to the category "%category%". A label must not be a category.',
        );

        $builder->add(
            'name',
            LocalisedTextType::class,
            [
                'label' => t('Name'),
                'data_class' => $labelClass::textClass(),
                'value_constraints' => [
                    new NotBlank(
                        message: 'Enter the name in both languages.',
                        normalizer: 'trim',
                    ),
                    $notLikeCategory,
                ],
            ],
        );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired([
            'data_class',
            'categories',
        ]);

        $resolver->setAllowedTypes(
            'data_class',
            'string',
        );
        $resolver->setAllowedTypes(
            'categories',
            TranslatableInterface::class . '[]',
        );
        $resolver->addAllowedValues(
            'data_class',
            static fn (string $class): bool => is_subclass_of(
                $class,
                LabelInterface::class,
            ),
        );
    }
}
