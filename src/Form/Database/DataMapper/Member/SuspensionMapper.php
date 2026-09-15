<?php

declare(strict_types=1);

namespace App\Form\Database\DataMapper\Member;

use App\Entity\Database\Decision;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Member\Suspension;
use App\Form\Database\DataMapper\AbstractDecisionMapper;
use DateTimeImmutable;
use Override;
use Symfony\Component\Form\FormInterface;

class SuspensionMapper extends AbstractDecisionMapper
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
        $since = $forms['since']->getData();
        $until = $forms['until']->getData();

        if (
            !$member instanceof Member
            || !$since instanceof DateTimeImmutable
            || !$until instanceof DateTimeImmutable
        ) {
            return;
        }

        $suspension = new Suspension();
        $suspension->sequence = 1;
        $suspension->setMember($member);
        $suspension->since = $since;
        $suspension->until = $until;
        $suspension->setDecision($decision);
    }
}
