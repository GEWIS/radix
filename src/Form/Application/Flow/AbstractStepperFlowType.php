<?php

declare(strict_types=1);

namespace App\Form\Application\Flow;

use Override;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\ButtonFlowInterface;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\Flow\FormFlowCursor;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\Flow\Type\FinishFlowType;
use Symfony\Component\Form\Flow\Type\NextFlowType;
use Symfony\Component\Form\Flow\Type\PreviousFlowType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;
use function array_keys;
use function array_search;
use function array_values;
use function count;
use function is_callable;
use function is_object;
use function sprintf;
use function Symfony\Component\Translation\t;

/**
 * A form that is filled in a step at a time, each step its own request, so the browser is never asked to validate a
 * control it cannot show. Every rule lives on the data object in the group named after the step that collects it.
 */
abstract class AbstractStepperFlowType extends AbstractFlowType
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ValidatorInterface $validator,
        protected readonly TranslatorInterface $translator,
    ) {
    }

    public static function storageKey(string $flowKey): string
    {
        return sprintf(
            '_sf_formflow.%s.%s',
            static::class,
            $flowKey,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildFormFlow(
        FormFlowBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add(
                'previous',
                PreviousFlowType::class,
                [
                    'label' => t('Back'),
                    // Symfony's own back button throws the submission away, which writes the step being left to the
                    // data object as emptiness. Keep it, and skip the checks instead.
                    'clear_submission' => false,
                    'validate' => false,
                    'validation_groups' => false,
                ],
            )
            ->add(
                'next',
                NextFlowType::class,
                ['label' => t('Next')],
            )
            ->add(
                'finish',
                FinishFlowType::class,
                [
                    'label' => $options['finish_label'],
                    // Symfony offers finishing on the last step alone, which a flow whose tail is built from
                    // records has no meaningful version of: naming a step says everything that has to be
                    // answered has been by here.
                    'include_if' => static function (FormFlowCursor $cursor) use ($options): bool {
                        $from = array_search(
                            $options['finish_from'],
                            $cursor->getSteps(),
                            true,
                        );

                        return false === $from
                            ? $cursor->isLastStep()
                            : $cursor->getStepIndex() >= $from;
                    },
                ],
            )
            ->add(
                'goto',
                GoToStepFlowType::class,
                ['label' => false],
            );

        // A stored step may have lost its record; landing on the last step that is always there beats refusing.
        $builder->setStepAccessor(new KnownStepAccessor(
            $builder->getStepAccessor(),
            $builder,
            $options['finish_from'],
        ));

        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            fn (FormEvent $event) => $this->refuseAnUnfinishedForm(
                $event,
                $options['step_labels'],
                $options['step_groups'],
            ),
        );
    }

    /**
     * Handing a step in judges that step alone, so a step that is left behind is never judged again. Finishing is the
     * one moment the whole thing has to be true at once, and the step that is wanting is named, because it is not the
     * step on screen and there is nothing on screen for the message to point at.
     *
     * @param array<string, mixed>                $labels
     * @param array<string, array<string, mixed>> $groups
     */
    private function refuseAnUnfinishedForm(
        FormEvent $event,
        array $labels,
        array $groups,
    ): void {
        $flow = $event->getForm();
        $data = $event->getData();

        if (
            !$flow instanceof FormFlowInterface
            || !is_object($data)
        ) {
            return;
        }

        if (!$this->isFinishing($flow)) {
            return;
        }

        foreach ($flow->getCursor()->getSteps() as $step) {
            if (
                $this->holds(
                    $step,
                    $data,
                    $groups,
                )
            ) {
                continue;
            }

            $label = $this->label(
                $labels,
                $step,
            );
            $group = $groups[$step]['label'] ?? null;

            $this->refuse(
                $flow,
                null === $group
                    ? $this->translator->trans(
                        'This cannot be saved yet: %step% is not filled in.',
                        ['%step%' => $label],
                    )
                    : $this->translator->trans(
                        'This cannot be saved yet: the %step% of %group% is not filled in.',
                        [
                            '%step%' => $label,
                            '%group%' => $group,
                        ],
                    ),
            );

            return;
        }
    }

    /**
     * Whether a step holds together: by the rules in the group named after it, or, for a step built from a record
     * the data object does not hold, by what the step says itself. One answer for the tick, the jump and the finish.
     *
     * @param array<string, array<string, mixed>> $groups
     */
    private function holds(
        string $name,
        mixed $data,
        array $groups,
    ): bool {
        $complete = $groups[$name]['complete'] ?? null;

        if (is_callable($complete)) {
            return (bool) $complete($data);
        }

        return !is_object($data)
            || 0 === count($this->validator->validate(
                $data,
                null,
                [$name],
            ));
    }

    /**
     * @param FormFlowInterface<mixed> $flow
     */
    protected function isFinishing(FormFlowInterface $flow): bool
    {
        $button = $flow->getClickedButton();

        return $button instanceof ButtonFlowInterface
            && $button->isFinishAction();
    }

    /**
     * @param FormFlowInterface<mixed> $flow
     */
    protected function refuse(
        FormFlowInterface $flow,
        string $message,
    ): void {
        $flow->addError(new FormError($message));
    }

    /**
     * @param array<string, mixed> $labels
     */
    private function label(
        array $labels,
        string $step,
    ): string {
        $label = $labels[$step] ?? $step;

        return $label instanceof TranslatableInterface
            ? $label->trans($this->translator)
            : (string) $label;
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildViewFlow(
        FormView $view,
        FormFlowInterface $form,
        array $options,
    ): void {
        $view->vars['flow_key'] = $options['flow_key'];
        $view->vars['step_labels'] = $options['step_labels'];
        $view->vars['stepper'] = $this->stepper(
            $view,
            $form,
            $options['step_groups'],
        );
    }

    /**
     * @param FormFlowInterface<mixed>            $form
     * @param array<string, array<string, mixed>> $groups
     *
     * @return array{
     *     top: list<array{name: string, label: mixed, position: int, state: string}>,
     *     group: ?array<string, mixed>,
     * }
     */
    private function stepper(
        FormView $view,
        FormFlowInterface $form,
        array $groups,
    ): array {
        /** @var array<string, array{index: int}> $visible */
        $visible = $view->vars['visible_steps'] ?? [];
        $data = $form->getData();
        $reachable = $this->reachable(
            $visible,
            $data,
            $currentIndex = $form->getCursor()->getStepIndex(),
            $groups,
        );
        $cursor = $form->getCursor();
        $current = $cursor->getCurrentStep();
        $under = $groups[$current]['under'] ?? null;

        $top = [];
        $position = 0;
        foreach ($visible as $name => $step) {
            if (
                array_key_exists(
                    $name,
                    $groups,
                )
            ) {
                continue;
            }

            ++$position;
            $top[] = [
                'name' => $name,
                'label' => $view->vars['step_labels'][$name] ?? $name,
                'position' => $position,
                'reachable' => $reachable[$name] ?? false,
                'state' => $this->state(
                    $name === $under,
                    $step['index'],
                    $currentIndex,
                ),
            ];
        }

        return [
            'top' => $top,
            'group' => null === $under
                ? null
                : $this->group(
                    $view,
                    $visible,
                    $groups,
                    $current,
                    $currentIndex,
                    $data,
                ),
        ];
    }

    /**
     * Which steps may be moved to straight away: a step is offered as soon as every step before it holds together.
     *
     * @param array<string, array{index: int}>    $visible
     * @param array<string, array<string, mixed>> $groups
     *
     * @return array<string, bool>
     */
    private function reachable(
        array $visible,
        mixed $data,
        int $currentIndex,
        array $groups,
    ): array {
        $reachable = [];
        $behind = true;

        foreach ($visible as $name => $step) {
            $reachable[$name] = $behind || $step['index'] <= $currentIndex;

            if (!$behind) {
                continue;
            }

            $behind = $this->holds(
                $name,
                $data,
                $groups,
            );
        }

        return $reachable;
    }

    private function state(
        bool $holdsCurrent,
        int $index,
        int $currentIndex,
    ): string {
        if (
            $holdsCurrent
            || $index === $currentIndex
        ) {
            return 'current';
        }

        return $index < $currentIndex
            ? 'complete'
            : 'upcoming';
    }

    /**
     * @param list<array{done: bool}> $steps
     */
    private function progress(array $steps): string
    {
        $done = 0;
        foreach ($steps as $step) {
            if (!$step['done']) {
                continue;
            }

            ++$done;
        }

        return sprintf(
            '%d/%d',
            $done,
            count($steps),
        );
    }

    /**
     * @param array<string, array{index: int}>    $visible
     * @param array<string, array<string, mixed>> $groups
     *
     * @return array<string, mixed>
     */
    private function group(
        FormView $view,
        array $visible,
        array $groups,
        string $current,
        int $currentIndex,
        mixed $data,
    ): array {
        $key = $groups[$current]['group'];

        $byGroup = [];
        $siblings = [];
        foreach ($visible as $name => $step) {
            $group = $groups[$name] ?? null;
            if (null === $group) {
                continue;
            }

            if (
                !array_key_exists(
                    $group['group'],
                    $siblings,
                )
            ) {
                $siblings[$group['group']] = [
                    'name' => $name,
                    'label' => $group['label'],
                    'number' => $group['number'] ?? null,
                    'current' => $group['group'] === $key,
                ];
                $byGroup[$group['group']] = [];
            }

            $byGroup[$group['group']][] = [
                'name' => $name,
                'label' => $view->vars['step_labels'][$name] ?? $name,
                'position' => count($byGroup[$group['group']]) + 1,
                'done' => $this->holds(
                    $name,
                    $data,
                    $groups,
                ),
                'state' => $this->state(
                    false,
                    $step['index'],
                    $currentIndex,
                ),
            ];
        }

        $steps = $byGroup[$key];
        foreach ($siblings as $group => $sibling) {
            $siblings[$group]['state'] = $this->progress($byGroup[$group]);
        }

        $order = array_keys($siblings);
        $at = (int) array_search(
            $key,
            $order,
            true,
        );

        return [
            'label' => $groups[$current]['label'],
            'collection' => $groups[$current]['collection'] ?? null,
            'number' => $groups[$current]['number'] ?? null,
            'state' => $this->progress($steps),
            'overview' => $groups[$current]['under'],
            'position' => $at + 1,
            'total' => count($order),
            'steps' => $steps,
            'siblings' => array_values($siblings),
            'previous' => isset($order[$at - 1]) ? $siblings[$order[$at - 1]]['name'] : null,
            'next' => isset($order[$at + 1]) ? $siblings[$order[$at + 1]]['name'] : null,
        ];
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'step_property_path' => 'step',
            // The controller clears the flow itself, so a run that ends in a rejection can be sent back to the step
            // that has to be corrected rather than starting over.
            'auto_reset' => false,
            'finish_label' => t('Save'),
            'step_labels' => [],
            'finish_from' => null,
            'step_groups' => [],
            'flow_key' => null,
        ]);

        $resolver->setAllowedTypes(
            'step_labels',
            'array',
        );

        $resolver->setAllowedTypes(
            'step_groups',
            'array',
        );

        $resolver->setAllowedTypes(
            'finish_from',
            [
                'null',
                'string',
            ],
        );

        $resolver->setAllowedTypes(
            'flow_key',
            [
                'null',
                'string',
            ],
        );

        // Symfony keys a flow by form type alone. A form that edits needs the record in the key too, or opening a
        // second one carries on where the first was left off.
        $resolver->setDefault(
            'data_storage',
            function (Options $options): ?SessionDataStorage {
                if (null === $options['flow_key']) {
                    return null;
                }

                return new SessionDataStorage(
                    static::storageKey($options['flow_key']),
                    $this->requestStack,
                );
            },
        );
    }
}
