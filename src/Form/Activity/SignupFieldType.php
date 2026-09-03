<?php

declare(strict_types=1);

namespace App\Form\Activity;

use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\SignupField;
use App\Form\Application\LocalisedTextType;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function strval;
use function Symfony\Component\Translation\t;

/**
 * A custom question on a sign-up list ({@see SignupField}). The "number" type uses the min/max bounds; the "choice"
 * type uses the nested options collection.
 *
 * @extends AbstractType<SignupField>
 */
class SignupFieldType extends AbstractType
{
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add(
                'name',
                LocalisedTextType::class,
                ['label' => t('Question')],
            )
            ->add(
                'type',
                EnumType::class,
                [
                    'label' => t('Type'),
                    'class' => SignupFieldTypes::class,
                ],
            )
            ->add(
                'isSensitive',
                CheckboxType::class,
                [
                    'label' => t('Sensitive (only visible to the board and organiser)'),
                    'required' => false,
                ],
            )
            ->add(
                'minimumValue',
                IntegerType::class,
                [
                    'label' => t('Minimum value (number type)'),
                    'required' => false,
                ],
            )
            ->add(
                'maximumValue',
                IntegerType::class,
                [
                    'label' => t('Maximum value (number type)'),
                    'required' => false,
                ],
            )
            ->add(
                'options',
                CollectionType::class,
                [
                    'label' => false,
                    'entry_type' => SignupOptionType::class,
                    'entry_options' => ['label' => false],
                    'allow_add' => true,
                    'allow_delete' => true,
                    'by_reference' => false,
                    'prototype' => true,
                    'prototype_name' => '__option__',
                    'block_prefix' => 'signup_option_collection',
                ],
            );

        // The organiser reorders the questions by dragging (see the sortable Stimulus controller); the new order is
        // written into this hidden input per entry and mapped onto the position column. HiddenType keeps the value as
        // a string, so transform it to/from the entity's int property.
        $builder->add(
            'position',
            HiddenType::class,
            [
                'attr' => ['data-sortable-target' => 'position'],
            ],
        );
        $builder->get('position')->addModelTransformer(new CallbackTransformer(
            static fn (?int $value): string => strval($value ?? 0),
            static fn (?string $value): int => (int) $value,
        ));

        // After binding, drop the bounds only the number type uses, so a question whose type changed cannot keep
        // values nothing shows any more and the cloner would carry into future revisions.
        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            $this->clearInapplicableBounds(...),
        );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SignupField::class,
            // A number is answered within bounds, so a question of that type has to name them; validated at the
            // object level so the rule can depend on the chosen type.
            'constraints' => [new Callback($this->validateBounds(...))],
        ]);
    }

    public function validateBounds(
        mixed $field,
        ExecutionContextInterface $context,
    ): void {
        if (
            !$field instanceof SignupField
            || SignupFieldTypes::Number !== $field->getType()
        ) {
            return;
        }

        $minimum = $field->getMinimumValue();
        $maximum = $field->getMaximumValue();

        if (null === $minimum) {
            $context->buildViolation(t(
                'Enter the lowest value that may be entered.',
                [],
                'validators',
            )->getMessage())
                ->atPath('minimumValue')
                ->addViolation();
        }

        if (null === $maximum) {
            $context->buildViolation(t(
                'Enter the highest value that may be entered.',
                [],
                'validators',
            )->getMessage())
                ->atPath('maximumValue')
                ->addViolation();
        }

        if (
            null === $minimum
            || null === $maximum
            || $maximum >= $minimum
        ) {
            return;
        }

        $context->buildViolation(t(
            'The highest value must not be below the lowest value.',
            [],
            'validators',
        )->getMessage())
            ->atPath('maximumValue')
            ->addViolation();
    }

    private function clearInapplicableBounds(FormEvent $event): void
    {
        $field = $event->getData();

        if (
            !$field instanceof SignupField
            || SignupFieldTypes::Number === $field->getType()
        ) {
            return;
        }

        $field->setMinimumValue(null);
        $field->setMaximumValue(null);
    }
}
