<?php

declare(strict_types=1);

namespace App\Form\Database\DataMapper\Member;

use App\Entity\Database\Decision;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Member\Warning;
use App\Form\Database\DataMapper\AbstractDecisionMapper;
use Override;
use Symfony\Component\Form\FormInterface;

class WarningMapper extends AbstractDecisionMapper
{
    /**
     * @param array<string, FormInterface<mixed>> $forms
     */
    #[Override]
    protected function mapSubDecisions(
        array $forms,
        Decision $decision,
    ): void {
        $member = $forms['member']->getData();

        if (!$member instanceof Member) {
            return;
        }

        $warning = new Warning();
        $warning->setSequence(1);
        $warning->setMember($member);
        $warning->setDecision($decision);
    }
}
