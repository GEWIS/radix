<?php

declare(strict_types=1);

namespace App\Form\Activity\ActivityFlow;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Form\Activity\SignupListType;
use DateTime;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;
use function trim;

/**
 * Edited on the revision itself rather than through the flow's data object: a tree of records with its own editor,
 * asked for on the last step. The windows are judged against the schedule and the languages the data object carries
 * by the time this step is reached.
 *
 * @extends AbstractType<ActivityRevision>
 */
class SignupListsStepType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder->add(
            'signupLists',
            CollectionType::class,
            [
                'label' => t('Sign-up lists'),
                'entry_type' => SignupListType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__list__',
                // Render each list as a collapsible panel (see the `signup_list_collection` form theme); the nested
                // field/option collections keep the generic `collection` theme.
                'block_prefix' => 'signup_list_collection',
            ],
        );

        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            $this->validateLists(...),
        );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ActivityRevision::class,
            'label' => false,
        ]);
    }

    private function validateLists(FormEvent $event): void
    {
        $form = $event->getForm();
        $activity = $form->getParent()?->getData();

        if (!$activity instanceof ActivityData) {
            return;
        }

        $now = new DateTime();
        $beginTime = null !== $activity->beginTime
            ? DateTime::createFromInterface($activity->beginTime)
            : null;

        foreach ($form->get('signupLists') as $listForm) {
            $this->validateWindow(
                $listForm,
                $now,
                $beginTime,
            );
            $this->requireLocalisedText(
                $listForm->get('name'),
                $activity,
            );

            foreach ($listForm->get('fields') as $fieldForm) {
                $this->requireLocalisedText(
                    $fieldForm->get('name'),
                    $activity,
                );

                if (SignupFieldTypes::Choice !== $fieldForm->get('type')->getData()) {
                    continue;
                }

                $this->validateOptions(
                    $fieldForm,
                    $activity,
                );
            }
        }
    }

    /**
     * @param FormInterface<mixed> $listForm
     */
    private function validateWindow(
        FormInterface $listForm,
        DateTime $now,
        ?DateTime $beginTime,
    ): void {
        $openForm = $listForm->get('openDate');
        $closeForm = $listForm->get('closeDate');
        $openDate = $openForm->getData();
        $closeDate = $closeForm->getData();

        // A new sign-up list must open in the future. Skipped once the list has opened (the opening date is then
        // locked, so an already-past value is never newly rejected).
        if (
            !$openForm->isDisabled()
            && $openDate instanceof DateTime
            && $openDate <= $now
        ) {
            $this->reject(
                $openForm,
                'The sign-up list must open in the future.',
            );
        }

        if (
            $openDate instanceof DateTime
            && $closeDate instanceof DateTime
            && $openDate >= $closeDate
        ) {
            $this->reject(
                $closeForm,
                'The sign-up list must open before it closes.',
            );
        }

        if (
            !$closeDate instanceof DateTime
            || !$beginTime instanceof DateTime
            || $closeDate < $beginTime
        ) {
            return;
        }

        $this->reject(
            $closeForm,
            'The sign-up list must close before the activity starts.',
        );
    }

    /**
     * A choice field may preselect at most one option as its default. The editor enforces this client-side (the
     * checkboxes are mutually exclusive), so this only guards a tampered submission.
     *
     * @param FormInterface<mixed> $fieldForm
     */
    private function validateOptions(
        FormInterface $fieldForm,
        ActivityData $activity,
    ): void {
        $defaultCount = 0;

        foreach ($fieldForm->get('options') as $optionForm) {
            $this->requireLocalisedText(
                $optionForm->get('value'),
                $activity,
            );

            if (true !== $optionForm->get('isDefault')->getData()) {
                continue;
            }

            ++$defaultCount;
        }

        if ($defaultCount <= 1) {
            return;
        }

        $this->reject(
            $fieldForm,
            'Only one option can be preselected as the default.',
        );
    }

    /**
     * @param FormInterface<mixed> $localised
     */
    private function requireLocalisedText(
        FormInterface $localised,
        ActivityData $activity,
    ): void {
        foreach (
            [
                'valueNL' => [
                    $activity->languageDutch,
                    'Fill in the Dutch text.',
                ],
                'valueEN' => [
                    $activity->languageEnglish,
                    'Fill in the English text.',
                ],
            ] as $child => [$enabled, $message]
        ) {
            if (
                !$enabled
                || '' !== trim((string) $localised->get($child)->getData())
            ) {
                continue;
            }

            $this->reject(
                $localised->get($child),
                $message,
            );
        }
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function reject(
        FormInterface $form,
        string $message,
    ): void {
        $form->addError(new FormError(
            $this->translator->trans(
                $message,
                [],
                'validators',
            ),
        ));
    }
}
