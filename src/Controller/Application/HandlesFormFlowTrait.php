<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\Entity\Application\Enums\AlertTypes;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\Flow\FormFlowTypeInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;

/**
 * Shared handling for the controllers that drive an {@see \App\Form\Application\Flow\AbstractStepperFlowType}.
 */
trait HandlesFormFlowTrait
{
    /**
     * `createForm()` answers the base interface as far as static analysis is concerned, and every caller here needs
     * the flow's own methods.
     *
     * @param class-string<FormFlowTypeInterface> $type
     * @param array<string, mixed>                $options
     */
    private function createFlow(
        string $type,
        mixed $data = null,
        array $options = [],
    ): FormFlowInterface {
        $flow = $this->createForm(
            $type,
            $data,
            $options,
        );
        assert($flow instanceof FormFlowInterface);

        return $flow;
    }

    /**
     * What is wrong is named on the fields themselves; this is only the nudge to look at them.
     */
    private function flashRejectedStep(
        FormFlowInterface $flow,
        TranslatorInterface $translator,
    ): void {
        if (
            !$flow->isSubmitted()
            || $flow->isValid()
        ) {
            return;
        }

        $this->addFlash(
            AlertTypes::Danger->value,
            $translator->trans('Some of the entered information is missing or incorrect.'),
        );
    }
}
