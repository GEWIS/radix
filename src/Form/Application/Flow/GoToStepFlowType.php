<?php

declare(strict_types=1);

namespace App\Form\Application\Flow;

use Override;
use Symfony\Component\Form\Flow\AbstractButtonFlowType;
use Symfony\Component\Form\Flow\ButtonFlowInterface;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_search;
use function is_string;

/**
 * A button that moves the flow to the step it names, forwards as well as back. Symfony's own back button only ever
 * moves towards the start, which is not enough for a flow whose steps are built from records. Nothing is validated
 * on the way, the same as the back button.
 */
class GoToStepFlowType extends AbstractButtonFlowType
{
    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder->setAttribute(
            'action',
            'goto',
        );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'handler' => static function (
                mixed $data,
                ButtonFlowInterface $button,
                FormFlowInterface $flow,
            ): void {
                $target = $button->getViewData();
                $steps = $flow->getCursor()->getSteps();

                $index = is_string($target)
                    ? array_search(
                        $target,
                        $steps,
                        true,
                    )
                    : false;

                if (false === $index) {
                    return;
                }

                if ($index < $flow->getCursor()->getStepIndex()) {
                    $flow->movePrevious($target);

                    return;
                }

                while (
                    $flow->getCursor()->getCurrentStep() !== $target
                    && $flow->getCursor()->canMoveNext()
                ) {
                    $flow->moveNext();
                }
            },
            'clear_submission' => false,
            'validate' => false,
            'validation_groups' => false,
        ]);
    }
}
