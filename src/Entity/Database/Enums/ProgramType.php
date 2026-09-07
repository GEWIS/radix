<?php

declare(strict_types=1);

namespace App\Entity\Database\Enums;

use App\Entity\Application\PriorityTierInterface;
use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;

enum ProgramType: string implements PriorityTierInterface
{
    case Bachelor = 'bachelor';
    case Master = 'master';
    case Doctorate = 'doctorate';
    case Other = 'other';

    /**
     * @return list<self>
     */
    #[Override]
    public static function defaultOrder(): array
    {
        return [
            self::Bachelor,
            self::Master,
            self::Doctorate,
            self::Other,
        ];
    }

    /**
     * @return list<list<self>>
     */
    #[Override]
    public static function defaultRanks(): array
    {
        return array_map(
            static fn (self $tier): array => [$tier],
            self::defaultOrder(),
        );
    }

    public function getName(): TranslatableMessage
    {
        return match ($this) {
            self::Bachelor => new TranslatableMessage('Bachelor'),
            self::Master => new TranslatableMessage('(Pre-)master'),
            self::Doctorate => new TranslatableMessage('EngD or PhD'),
            self::Other => new TranslatableMessage('Other'),
        };
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return $this->getName()->trans(
            $translator,
            $locale,
        );
    }
}
