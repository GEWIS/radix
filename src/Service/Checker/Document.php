<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Database\Meeting as MeetingModel;
use App\Entity\Database\SubDecision\Financial\Budget as BudgetModel;
use App\Entity\Database\SubDecision\OrganRegulation as OrganRegulationModel;
use App\Repository\Checker\DocumentRepository;

class Document
{
    public function __construct(private readonly DocumentRepository $documentRepository)
    {
    }

    /**
     * @return array<array-key, BudgetModel>
     */
    public function getBudgetsDuringMeeting(MeetingModel $meeting): array
    {
        return $this->documentRepository->findBudgetsDuringMeeting($meeting);
    }

    /**
     * @return array<array-key, OrganRegulationModel>
     */
    public function getOrganRegulationsDuringMeeting(MeetingModel $meeting): array
    {
        return $this->documentRepository->findOrganRegulationsDuringMeeting($meeting);
    }
}
