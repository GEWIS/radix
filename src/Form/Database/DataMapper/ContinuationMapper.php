<?php

declare(strict_types=1);

namespace App\Form\Database\DataMapper;

use App\Entity\Database\Decision;
use App\Entity\Database\SubDecision\Continuation;
use App\Entity\Database\SubDecision\Foundation;
use Override;
use Symfony\Component\Form\FormInterface;

class ContinuationMapper extends AbstractDecisionMapper
{
    /**
     * @param array<string, FormInterface<mixed>> $forms
     */
    #[Override]
    protected function mapSubDecisions(
        array $forms,
        Decision $decision,
    ): void {
        $foundation = $forms['subdecision']->getData();

        if (!$foundation instanceof Foundation) {
            return;
        }

        $continuation = new Continuation();
        $continuation->setFoundation($foundation);
        $continuation->setSequence(1);
        $continuation->setDecision($decision);
    }
}
