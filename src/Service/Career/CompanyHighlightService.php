<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Career\Company;
use App\Entity\Career\Enums\CompanyAuditVerbs;
use App\Entity\User\CompanyUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which of a company's vacancies go on the career landing page.
 *
 * The selection is already on the package by the time this is reached, so all that is left is to note who changed it
 * and commit the two together: a highlight change with no timeline entry behind it is a change nobody can account for.
 */
final readonly class CompanyHighlightService
{
    public function __construct(
        private CompanyAuditLogger $auditLogger,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function saveSelection(
        Company $company,
        CompanyUser $companyUser,
    ): void {
        $this->auditLogger->log(
            $company,
            $companyUser,
            CompanyAuditVerbs::HighlightSelectionChanged,
        );

        $this->entityManager->flush();
    }
}
