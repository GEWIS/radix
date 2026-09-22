<?php

declare(strict_types=1);

namespace App\Form\Application;

use App\Entity\Application\Enums\Languages;
use App\Repository\Application\LabelRepositoryInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_keys;
use function intval;
use function Symfony\Component\Translation\t;

/**
 * The label picker of a revision form. A retired label is not offered, but one the revision already carries stays in
 * the list, so an edit cannot silently drop it.
 */
trait BuildsLabelChoicesTrait
{
    abstract protected function labelRepository(): LabelRepositoryInterface;

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param int[]                       $currentIds
     */
    private function addLabelField(
        FormBuilderInterface $builder,
        array $currentIds,
    ): void {
        $names = $this->labelNames($currentIds);

        $builder->add(
            'labelIds',
            ChoiceType::class,
            [
                'label' => t('Labels'),
                'choices' => array_keys($names),
                'choice_label' => static fn (int $id): string => $names[$id],
                'multiple' => true,
                'expanded' => false,
                'autocomplete' => true,
                'required' => false,
            ],
        );
    }

    private static function configureLabelOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault(
            'current_label_ids',
            [],
        );
        $resolver->setAllowedTypes(
            'current_label_ids',
            'int[]',
        );
    }

    /**
     * Keyed by id rather than by name, because a retired label a revision still carries and the active label that
     * replaced it have the same name, and a choice list keyed by name would keep only one of the two.
     *
     * @param int[] $currentIds
     *
     * @return array<int, string>
     */
    private function labelNames(array $currentIds): array
    {
        $language = Languages::current();
        $names = [];

        foreach ($this->labelRepository()->findActiveWithName($currentIds) as $label) {
            $names[intval($label->id)] = $label->name->getText($language) ?? '';
        }

        return $names;
    }
}
