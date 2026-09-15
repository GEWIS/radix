<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\RenewalLinkRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use InvalidArgumentException;

/**
 * Membership / graduate status renewal links.
 */
#[Entity(repositoryClass: RenewalLinkRepository::class)]
class RenewalLink extends ActionLink
{
    /**
     * The member
     */
    #[ManyToOne(
        targetEntity: Member::class,
        inversedBy: 'renewalLinks',
    )]
    #[JoinColumn(
        name: 'member',
        referencedColumnName: 'lidnr',
        onDelete: 'cascade',
    )]
    public private(set) Member $member;

    /**
     * Current expiration
     */
    #[Column(type: 'date_immutable')]
    public private(set) DateTimeImmutable $currentExpiration;

    /**
     * New expiration
     * This is not neccessarily a year from the previous as in principle this
     * will be until the end of next association year.
     */
    #[Column(type: 'date_immutable')]
    public private(set) DateTimeImmutable $newExpiration;

    public function __construct(
        Member $member,
        DateTimeImmutable $newExpiration,
    ) {
        parent::__construct();

        $this->member = $member;
        $this->newExpiration = $newExpiration;
        $this->currentExpiration = $member->getExpiration();

        if ($this->currentExpiration >= $this->newExpiration) {
            throw new InvalidArgumentException('New expiration must be strictly later than current expiration');
        }
    }

    /**
     * We assume a link is valid until 30 days after the original membership expired
     * Then, people can still renew their membership after their account gets locked
     */
    public function linkExpired(): bool
    {
        $diff = new DateTimeImmutable()->diff($this->currentExpiration);

        return 1 === $diff->invert && ($diff->days > 30);
    }
}
