<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\AuditRenewalRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Override;

/**
 * Class for registering renewals by the member or another user
 */
#[Entity(repositoryClass: AuditRenewalRepository::class)]
class AuditRenewal extends AuditEntry
{
    protected const bool IMMUTABLE = true;

    /**
     * Expiration value before this renewal took place
     */
    #[Column(type: 'datetime_immutable')]
    public DateTimeImmutable $oldExpiration;

    /**
     * Expiration value after the renewal
     */
    #[Column(type: 'datetime_immutable')]
    public DateTimeImmutable $newExpiration;

    final public static function fromRenewalLink(RenewalLink $renewalLink): AuditRenewal
    {
        $auditRenewal = new AuditRenewal();
        $auditRenewal->oldExpiration = $renewalLink->currentExpiration;
        $auditRenewal->newExpiration = $renewalLink->newExpiration;
        $auditRenewal->setMember($renewalLink->member);

        return $auditRenewal;
    }

    /**
     * Check if the renewal was done by the member
     */
    private function isSelfRenewal(): bool
    {
        return null === $this->user;
    }

    private function getStringRenewalType(): string
    {
        return $this->isSelfRenewal()
            ? 'Self-renewal'
            : 'Renewal';
    }

    /**
     * Get a textual representation of this audit entry
     */
    #[Override]
    protected function getStringBodyFormatted(): string
    {
        return '<strong>%s</strong> of <emph>%s</emph> until <br/>%s';
    }

    /**
     * @return array<string>
     */
    #[Override]
    protected function getStringArguments(): array
    {
        return [
            $this->getStringRenewalType(),
            $this->member->getFullName(),
            $this->newExpiration->format('l j F Y'),
        ];
    }
}
