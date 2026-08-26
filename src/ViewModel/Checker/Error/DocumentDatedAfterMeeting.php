<?php

declare(strict_types=1);

namespace App\ViewModel\Checker\Error;

use App\Entity\Database\SubDecision;
use App\Entity\Database\SubDecision\Financial\Budget as BudgetModel;
use App\Entity\Database\SubDecision\Financial\Statement as StatementModel;
use App\Entity\Database\SubDecision\OrganRegulation as OrganRegulationModel;
use App\ViewModel\Checker\Error;
use Override;

use function sprintf;

/**
 * Error for when a budget, a financial statement or a body regulation carries a date later than the meeting that
 * approved it. A meeting can only decide on the version in front of it, so a later date describes a document that did
 * not exist yet.
 *
 * @extends Error<SubDecision>
 */
class DocumentDatedAfterMeeting extends Error
{
    /**
     * Held again beside the parent's, which is every kind of subdecision there is: the two things this error says
     * about the document are only on these two.
     */
    public function __construct(private readonly BudgetModel|OrganRegulationModel $document)
    {
        parent::__construct(
            $document->getDecision()->getMeeting(),
            $document,
        );
    }

    #[Override]
    public function asText(): string
    {
        return sprintf(
            '%s %s, version %s, is dated %s, this is after %s.',
            $this->kind(),
            $this->name(),
            $this->document->getVersion(),
            $this->document->getDate()->format('Y-m-d'),
            $this->getMeeting()->getDate()->format('Y-m-d'),
        );
    }

    private function kind(): string
    {
        return match (true) {
            $this->document instanceof StatementModel => 'Financial statement',
            $this->document instanceof BudgetModel => 'Budget',
            default => 'Body regulation',
        };
    }

    /**
     * What the document is called: a budget has a name of its own, a body regulation is known by the body it is of.
     */
    private function name(): string
    {
        return $this->document instanceof BudgetModel
            ? $this->document->getName()
            : $this->document->getAbbr();
    }
}
