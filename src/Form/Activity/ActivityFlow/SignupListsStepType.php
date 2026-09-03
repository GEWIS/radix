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
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_array;
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
            FormEvents::PRE_SUBMIT,
            $this->rememberLists(...),
        );
        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            $this->validateLists(...),
        );
    }

    /**
     * Tell the fields which languages the activity is written in, so the step can disable the ones that are off and
     * mark the ones that are on as required. They are answered a step earlier, where the `localised-fields` Stimulus
     * controller reads them off the checkboxes themselves; here it is handed the answer.
     *
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildView(
        FormView $view,
        FormInterface $form,
        array $options,
    ): void {
        $activity = $form->getParent()?->getData();

        $view->vars['language_dutch'] = !$activity instanceof ActivityData || $activity->languageDutch;
        $view->vars['language_english'] = !$activity instanceof ActivityData || $activity->languageEnglish;
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ActivityRevision::class,
            'label' => false,
        ]);
    }

    /**
     * Keep what the step was filled in with on the data object, which is what the flow carries between the steps: the
     * lists themselves hang off the revision, and that is built afresh on every request.
     */
    private function rememberLists(FormEvent $event): void
    {
        $activity = $event->getForm()->getParent()?->getData();

        if (!$activity instanceof ActivityData) {
            return;
        }

        $submitted = $event->getData();
        $lists = is_array($submitted)
            ? ($submitted['signupLists'] ?? [])
            : [];

        $activity->signupListsSubmission = is_array($lists)
            ? $lists
            : [];
    }

    private function validateLists(FormEvent $event): void
    {
        $form = $event->getForm();
        $activity = $form->getParent()?->getData();

        if (
            !$activity instanceof ActivityData
            || !self::isHandedIn($form)
        ) {
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
     * Whether the step is being handed in rather than only filled back in. Nothing is clicked while the step is
     * restored from what it last held, and the back button asks for what was filled in to be kept rather than to be
     * correct (it turns the validator off the same way, but these checks are made by hand and would still run).
     *
     * @param FormInterface<mixed> $form
     */
    private static function isHandedIn(FormInterface $form): bool
    {
        $root = $form->getRoot();

        if (!$root instanceof FormFlowInterface) {
            return true;
        }

        $button = $root->getClickedButton();

        if (!$button instanceof FormInterface) {
            return false;
        }

        // A button that asks for no groups asks for no checks; `false` is normalised to the empty list before it
        // reaches here, which is the same thing the validator itself reads.
        $groups = $button->getConfig()->getOption('validation_groups');

        return [] !== $groups
            && false !== $groups;
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
                t(
                    'The sign-up list must open in the future.',
                    [],
                    'validators',
                ),
            );
        }

        if (
            $openDate instanceof DateTime
            && $closeDate instanceof DateTime
            && $openDate >= $closeDate
        ) {
            $this->reject(
                $closeForm,
                t(
                    'The sign-up list must open before it closes.',
                    [],
                    'validators',
                ),
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
            t(
                'The sign-up list must close before the activity starts.',
                [],
                'validators',
            ),
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
            t(
                'Only one option can be preselected as the default.',
                [],
                'validators',
            ),
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
                    t(
                        'Fill in the Dutch text.',
                        [],
                        'validators',
                    ),
                ],
                'valueEN' => [
                    $activity->languageEnglish,
                    t(
                        'Fill in the English text.',
                        [],
                        'validators',
                    ),
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
     * A message rather than a literal, so the string is written where the extractor can see it: what is handed to a
     * method is invisible to it, and `make translations` deletes every message it cannot find.
     *
     * @param FormInterface<mixed> $form
     */
    private function reject(
        FormInterface $form,
        TranslatableMessage $message,
    ): void {
        $form->addError(new FormError($message->trans($this->translator)));
    }
}
