<?php

declare(strict_types=1);

namespace App\Form\Database\DataMapper\Board;

use App\Entity\Database\Decision;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Board\Candidacy;
use App\Entity\Database\SubDecision\Board\Candidate;
use App\Form\Database\DataMapper\AbstractDecisionMapper;
use Override;
use Symfony\Component\Form\FormInterface;

use function is_int;

/**
 * The decision opens by saying which board is being stood for, and every candidate after it is a name, so the year is
 * written down once instead of against every one of them.
 */
class CandidacyMapper extends AbstractDecisionMapper
{
    /**
     * @param array<string, FormInterface<mixed>> $forms
     */
    #[Override]
    protected function mapSubDecisions(
        array $forms,
        Decision $decision,
    ): void {
        $boardYear = $forms['boardYear']->getData();

        if (!is_int($boardYear)) {
            return;
        }

        $candidacy = new Candidacy();
        $candidacy->setSequence(1);
        $candidacy->setBoardYear($boardYear);
        $candidacy->setDecision($decision);

        // The sequence is the constitutional order the candidates were entered in, which is the order the decision
        // reads in and the one thing about it that cannot be changed afterwards.
        $sequence = 2;

        foreach ($forms['candidates']->getData() ?? [] as $member) {
            if (!$member instanceof Member) {
                continue;
            }

            $candidate = new Candidate();
            $candidate->setSequence($sequence++);
            $candidate->setMember($member);
            $candidate->setDecision($decision);
        }
    }
}
