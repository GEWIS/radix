<?php

declare(strict_types=1);

namespace App\Form\Database\Registration;

use App\Entity\Database\Enums\Studies;
use App\Form\Database\StudyChoices;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<RegistrationData>
 */
class StudyStepType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * A study as the select should label it, resolved while the view is built rather than at build time so the
     * footnoted labels follow the request locale.
     */
    private function studyLabel(Studies $study): Studies|TranslatableMessage
    {
        return StudyChoices::labelWithFootnote(
            $study,
            $this->translator,
        );
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
                'studentNumber',
                TextType::class,
                ['label' => t('TU/e student number')],
            )
            // Grouped by category, which is why this is not an `EnumType`; the choices are the enum cases all the
            // same.
            ->add(
                'study',
                ChoiceType::class,
                [
                    'label' => t('Study'),
                    'placeholder' => t('Select a study'),
                    'choices' => StudyChoices::grouped(),
                    'choice_label' => $this->studyLabel(...),
                    'choice_value' => static fn (?Studies $study): ?string => $study?->value,
                    'invalid_message' => 'Select an existing study.',
                ],
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true]);
    }
}
