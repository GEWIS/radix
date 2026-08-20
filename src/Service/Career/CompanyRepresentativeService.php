<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Career\Company;
use App\Entity\Career\Enums\CompanyAuditVerbs;
use App\Entity\User\CompanyUser;
use App\Entity\User\User;
use App\Repository\User\PasswordResetRepository;
use App\Security\User\Firewall;
use App\Service\User\SessionManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the committee can do to a company's representatives: shut one out, let them back in, remove them, and decide
 * which of them the board writes to.
 *
 * Each is one unit of work, audit entry included, because a timeline that does not say who did it is worth less than
 * no timeline. Sessions are terminated only after the row is committed: a representative signed out while the change
 * that shut them out is still uncommitted would simply sign back in.
 */
final readonly class CompanyRepresentativeService
{
    public function __construct(
        private CompanyAuditLogger $auditLogger,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private PasswordResetRepository $passwordResetRepository,
        private SessionManager $sessionManager,
    ) {
    }

    /**
     * Reports whether anything changed, which is false when they could not sign in to begin with.
     */
    public function disable(
        Company $company,
        CompanyUser $representative,
        User $actor,
    ): bool {
        if ($representative->isDisabled()) {
            return false;
        }

        $representative->setDisabledAt(new DateTime());

        // Somebody who cannot sign in cannot be the contact either, so the company is left without one and the board
        // is asked to appoint a replacement.
        if ($company->getPrimaryContact() === $representative) {
            $company->setPrimaryContact(null);
        }

        $this->auditLogger->log(
            $company,
            $actor,
            CompanyAuditVerbs::RepresentativeDisabled,
            $representative->getName(),
        );

        $this->entityManager->flush();

        $this->sessionManager->terminateAll(
            $representative,
            Firewall::Company->value,
        );

        return true;
    }

    /**
     * Reports whether anything changed, which is false when they could already sign in.
     */
    public function enable(
        Company $company,
        CompanyUser $representative,
        User $actor,
    ): bool {
        if (!$representative->isDisabled()) {
            return false;
        }

        $representative->setDisabledAt(null);

        $this->auditLogger->log(
            $company,
            $actor,
            CompanyAuditVerbs::RepresentativeEnabled,
            $representative->getName(),
        );

        $this->entityManager->flush();

        return true;
    }

    public function remove(
        Company $company,
        CompanyUser $representative,
        User $actor,
    ): void {
        $this->sessionManager->terminateAll(
            $representative,
            Firewall::Company->value,
        );
        $this->passwordResetRepository->deleteAllForCompanyUser($representative);

        $this->auditLogger->log(
            $company,
            $actor,
            CompanyAuditVerbs::RepresentativeRemoved,
            $representative->getName(),
        );

        $this->entityManager->remove($representative);
        $this->entityManager->flush();
    }

    public function makePrimaryContact(
        Company $company,
        CompanyUser $representative,
        User $actor,
    ): void {
        $company->setPrimaryContact($representative);

        $this->auditLogger->log(
            $company,
            $actor,
            CompanyAuditVerbs::PrimaryContactChanged,
            $representative->getName(),
        );

        $this->entityManager->flush();
    }
}
