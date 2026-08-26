<?php

declare(strict_types=1);

namespace App\Form\Application\Flow;

use Override;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\Flow\Type\FinishFlowType;
use Symfony\Component\Form\Flow\Type\NextFlowType;
use Symfony\Component\Form\Flow\Type\PreviousFlowType;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function sprintf;
use function Symfony\Component\Translation\t;

/**
 * A form that is filled in a step at a time, each step its own request, so the browser is never asked to validate a
 * control it cannot show. Every rule lives on the data object in the group named after the step that collects it.
 */
abstract class AbstractStepperFlowType extends AbstractFlowType
{
    public function __construct(private readonly RequestStack $requestStack)
    {
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
                ['label' => $options['finish_label']],
            );
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
        $view->vars['step_labels'] = $options['step_labels'];
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
            'flow_key' => null,
        ]);

        $resolver->setAllowedTypes(
            'step_labels',
            'array',
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
                    sprintf(
                        '_sf_formflow.%s.%s',
                        static::class,
                        $options['flow_key'],
                    ),
                    $this->requestStack,
                );
            },
        );
    }
}
