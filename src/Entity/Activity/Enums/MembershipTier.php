<?php

declare(strict_types=1);

namespace App\Entity\Activity\Enums;

use App\Entity\Application\PriorityTierInterface;
use App\Entity\Database\Enums\MembershipTypes;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

enum MembershipTier: string implements PriorityTierInterface
{
    case Ordinary = 'ordinary';
    case External = 'external';
    case Honorary = 'honorary';
    case Graduate = 'graduate';
    case NonMember = 'non-member';

    /**
     * @return list<self>
     */
    #[Override]
    public static function defaultOrder(): array
    {
        return [
            self::Ordinary,
            self::External,
            self::Honorary,
            self::Graduate,
            self::NonMember,
        ];
    }

    /**
     * What the association holds a membership of is one thing to a sign-up list, so the three kinds of it are
     * admitted together until an organiser says otherwise.
     *
     * @return list<list<self>>
     */
    #[Override]
    public static function defaultRanks(): array
    {
        return [
            [
                self::Ordinary,
                self::External,
                self::Honorary,
            ],
            [self::Graduate],
            [self::NonMember],
        ];
    }

    /**
     * The membership this tier stands for, or null for a sign-up the association holds no membership of at all.
     */
    public function membershipType(): ?MembershipTypes
    {
        return match ($this) {
            self::Ordinary => MembershipTypes::Ordinary,
            self::External => MembershipTypes::External,
            self::Honorary => MembershipTypes::Honorary,
            self::Graduate => MembershipTypes::Graduate,
            self::NonMember => null,
        };
    }

    public static function of(MembershipTypes $type): self
    {
        return match ($type) {
            MembershipTypes::Ordinary => self::Ordinary,
            MembershipTypes::External => self::External,
            MembershipTypes::Honorary => self::Honorary,
            MembershipTypes::Graduate => self::Graduate,
        };
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return $this->membershipType()?->trans(
            $translator,
            $locale,
        ) ?? $translator->trans(
            'Non-members',
            locale: $locale,
        );
    }
}
