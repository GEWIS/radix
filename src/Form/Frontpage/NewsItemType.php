<?php

declare(strict_types=1);

namespace App\Form\Frontpage;

use App\Entity\Frontpage\Enums\NewsCategory;
use App\Entity\Frontpage\FrontpageLocalisedText;
use App\Entity\Frontpage\NewsItem;
use App\Form\Application\LocalisedTextType;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

use function Symfony\Component\Translation\t;

/**
 * A piece of news, written in both languages. The bodies are markdown, edited with the same editor as an activity's
 * description.
 *
 * @extends AbstractType<NewsItem>
 */
class NewsItemType extends AbstractType
{
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $localised = ['data_class' => FrontpageLocalisedText::class];

        $builder
            ->add(
                'date',
                DateType::class,
                [
                    'label' => t('Date'),
                    'widget' => 'single_text',
                    'constraints' => [new NotBlank(message: 'Enter the date this news item carries.')],
                ],
            )
            ->add(
                'category',
                EnumType::class,
                [
                    'label' => t('Category'),
                    'class' => NewsCategory::class,
                ],
            )
            ->add(
                'pinned',
                CheckboxType::class,
                [
                    'label' => t('Pin to the top of the news'),
                    'required' => false,
                ],
            )
            ->add(
                'title',
                LocalisedTextType::class,
                $localised + [
                    'label' => t('Title'),
                    // Both languages are held to the same rule, because a limit on what a title may say is a property
                    // of the title rather than of the language it is written in.
                    'value_constraints' => [
                        new NotBlank(message: 'Enter the title in both languages.'),
                        new Length(max: 255),
                    ],
                ],
            )
            ->add(
                'content',
                LocalisedTextType::class,
                $localised + [
                    'label' => t('Content'),
                    'multiline' => true,
                    'value_constraints' => [new NotBlank(message: 'Write the text in both languages.')],
                ],
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NewsItem::class]);
    }
}
