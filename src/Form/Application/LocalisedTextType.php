<?php

declare(strict_types=1);

namespace App\Form\Application;

use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Application\LocalisedText as LocalisedTextModel;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_key_exists;
use function is_array;
use function Symfony\Component\Translation\t;

/**
 * Reusable sub-form for an {@see LocalisedTextModel}: a Dutch and an English value. The owning module passes its own
 * `data_class` (e.g. {@see ActivityLocalisedText} or a Career localised text). Values are read/written through the
 * entity's accessors via the field `getter`/`setter`, so the shared base needs no form-only setters.
 *
 * `value_constraints` applies to both languages at once, since a limit on what a field may say is a property of the
 * field rather than of the language it is written in.
 *
 * @extends AbstractType<LocalisedTextModel>
 */
class LocalisedTextType extends AbstractType
{
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $widget = true === $options['multiline']
            ? TextareaType::class
            : TextType::class;

        $builder
            ->add(
                'valueNL',
                $widget,
                [
                    'label' => t('Dutch'),
                    'required' => false,
                    'constraints' => $options['value_constraints'],
                    'getter' => static fn (LocalisedTextModel $text): ?string => $text->getValueNL(),
                    'setter' => static function (
                        LocalisedTextModel $text,
                        ?string $value,
                    ): void {
                        $text->updateValueNL($value);
                    },
                ],
            )
            ->add(
                'valueEN',
                $widget,
                [
                    'label' => t('English'),
                    'required' => false,
                    'constraints' => $options['value_constraints'],
                    'getter' => static fn (LocalisedTextModel $text): ?string => $text->getValueEN(),
                    'setter' => static function (
                        LocalisedTextModel $text,
                        ?string $value,
                    ): void {
                        $text->updateValueEN($value);
                    },
                ],
            );

        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            $this->keepAbsentLanguages(...),
        );
    }

    /**
     * A language that is switched off is disabled in the browser and so is never handed in. Read that absence as "no
     * answer" rather than as an erasure, the way the flat per-language fields elsewhere keep whatever a language that
     * is off already had.
     */
    private function keepAbsentLanguages(FormEvent $event): void
    {
        $submitted = $event->getData();
        $text = $event->getForm()->getData();

        if (
            !is_array($submitted)
            || !$text instanceof LocalisedTextModel
        ) {
            return;
        }

        foreach (
            [
                'valueNL' => $text->getValueNL(),
                'valueEN' => $text->getValueEN(),
            ] as $child => $current
        ) {
            if (
                array_key_exists(
                    $child,
                    $submitted,
                )
            ) {
                continue;
            }

            $submitted[$child] = $current;
        }

        $event->setData($submitted);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ActivityLocalisedText::class,
            'multiline' => false,
            'value_constraints' => [],
        ]);
        $resolver->setAllowedTypes(
            'multiline',
            'bool',
        );
        $resolver->setAllowedTypes(
            'value_constraints',
            'array',
        );
    }
}
