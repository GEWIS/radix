<?php

declare(strict_types=1);

namespace App\Form\Database\DataMapper;

use App\Entity\Database\Decision;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\OrganRegulation;
use DateTimeImmutable;
use Override;
use Symfony\Component\Form\FormInterface;

use function is_string;

class OrganRegulationMapper extends AbstractDecisionMapper
{
    /**
     * @param array<string, FormInterface> $forms
     */
    #[Override]
    protected function mapSubDecisions(
        array $forms,
        Decision $decision,
    ): void {
        $organType = $forms['type']->getData();
        $abbr = $forms['abbr']->getData();
        $date = $forms['date']->getData();
        $author = $forms['author']->getData();
        $version = $forms['version']->getData();

        if (
            !$organType instanceof OrganTypes
            || !is_string($abbr)
            || !$date instanceof DateTimeImmutable
            || !$author instanceof Member
            || !is_string($version)
        ) {
            return;
        }

        // Only organs that can have organ regulations at all. The form rejects the others with a message of their
        // own, so building nothing here leaves that message to do the talking.
        if (!$organType->hasOrganRegulations()) {
            return;
        }

        $subdecision = new OrganRegulation();
        $subdecision->sequence = 1;
        $subdecision->organType = $organType;
        $subdecision->date = $date;
        $subdecision->abbr = $abbr;
        $subdecision->setMember($author);
        $subdecision->version = $version;
        $subdecision->approval = (bool) $forms['approve']->getData();
        $subdecision->changes = (bool) $forms['changes']->getData();
        $subdecision->setDecision($decision);
    }
}
