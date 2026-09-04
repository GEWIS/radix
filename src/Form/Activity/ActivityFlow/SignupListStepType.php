<?php

declare(strict_types=1);

namespace App\Form\Activity\ActivityFlow;

use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\SignupList;
use App\Form\Activity\Enums\SignupListSection;
use App\Form\Activity\SignupListType;
use DateTime;
use Override;
use Symfony\Component\Form\AbstractType;
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

use function assert;
use function Symfony\Component\Translation\t;
use function trim;

/**
 * @extends AbstractType<SignupList>
 */
class SignupListStepType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Override]
    public function getParent(): string
    {
        return SignupListType::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            $this->check(...),
        );
    }

    /**
     * Tell the fields which languages the activity is written in, so the step can disable the ones that are off and
     * mark the ones that are on as required.
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
        $activity = $form->getRoot()->getData();

        $section = $options['section'];
        assert($section instanceof SignupListSection);

        $view->vars['section'] = $section;
        $view->vars['section_description'] = $section->description($this->translator);
        $view->vars['activity_begins'] = $activity instanceof ActivityData
            ? $activity->beginTime
            : null;
        $view->vars['language_dutch'] = !$activity instanceof ActivityData || $activity->languageDutch;
        $view->vars['language_english'] = !$activity instanceof ActivityData || $activity->languageEnglish;
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault(
            'label',
            false,
        );
    }

    private function check(FormEvent $event): void
    {
        $form = $event->getForm();
        $activity = $form->getRoot()->getData();

        if (
            !$activity instanceof ActivityData
            || !self::isHandedIn($form)
        ) {
            return;
        }

        match ($form->getConfig()->getOption('section')) {
            SignupListSection::Basics => $this->checkBasics(
                $form,
                $activity,
            ),
            SignupListSection::Questions => $this->checkQuestions(
                $form,
                $activity,
            ),
            default => null,
        };
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function checkBasics(
        FormInterface $form,
        ActivityData $activity,
    ): void {
        $beginTime = null !== $activity->beginTime
            ? DateTime::createFromInterface($activity->beginTime)
            : null;

        $this->validateWindow(
            $form,
            new DateTime(),
            $beginTime,
        );
        $this->requireLocalisedText(
            $form->get('name'),
            $activity,
        );
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function checkQuestions(
        FormInterface $form,
        ActivityData $activity,
    ): void {
        foreach ($form->get('fields') as $fieldForm) {
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
