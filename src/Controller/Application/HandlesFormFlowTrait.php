<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\Entity\Application\Enums\AlertTypes;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\Flow\FormFlowTypeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;
use function bin2hex;
use function random_bytes;

/**
 * Shared handling for the controllers that drive an {@see \App\Form\Application\Flow\AbstractStepperFlowType}.
 */
trait HandlesFormFlowTrait
{
    /**
     * The query parameter a run of a flow is recognised by.
     */
    private const string FLOW_RUN = 'flow';

    /**
     * The key the flow being filled in is kept under. Every arrival gets one of its own, or opening a form that was
     * abandoned half-way would carry on where it was left off rather than start over. It travels in the address, so
     * reloading a step stays in the same run.
     *
     * Answers a redirect on the first arrival, which the caller has to return.
     */
    private function flowRun(Request $request): string|RedirectResponse
    {
        $run = $request->query->getString(self::FLOW_RUN);

        if ('' !== $run) {
            return $run;
        }

        return $this->redirectToRoute(
            $request->attributes->getString('_route'),
            $request->attributes->all()['_route_params'] + [
                self::FLOW_RUN => bin2hex(random_bytes(8)),
            ],
        );
    }

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
