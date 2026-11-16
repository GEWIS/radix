<?php

declare(strict_types=1);

namespace App\Form\Decision\OrganPage;

use App\Form\Application\BuildsLabelChoicesTrait;
use App\Repository\Application\LabelRepositoryInterface;
use App\Repository\Decision\OrganLabelRepository;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * The two descriptions, written per language, and the labels the body picks for its page. The language checkboxes
 * drive the `localised-fields` Stimulus controller and are mapped, because the rule on the data object is read
 * against them.
 *
 * @extends AbstractType<OrganPageData>
 */
class PageStepType extends AbstractType
{
    use BuildsLabelChoicesTrait;

    public function __construct(
        private readonly OrganLabelRepository $organLabelRepository,
    ) {
    }

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

        // Labels are the committees' own, so a body of any other kind edits its page without the picker.
        if (true !== $options['labels_enabled']) {
            return;
        }

        $this->addLabelField(
            $builder,
            $options['current_label_ids'],
        );
    }

    #[Override]
    protected function labelRepository(): LabelRepositoryInterface
    {
        return $this->organLabelRepository;
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            // Off by default: a form that forgets the offer drops the picker rather than showing it to a body it
            // cannot use.
            'labels_enabled' => false,
        ]);

        self::configureLabelOptions($resolver);
    }
}
