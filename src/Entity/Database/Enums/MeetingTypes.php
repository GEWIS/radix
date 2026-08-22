<?php

declare(strict_types=1);

namespace App\Entity\Database\Enums;

use InvalidArgumentException;
use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function array_map;
use function array_merge;

/**
 * Enum for the different address types.
 */
enum MeetingTypes: string implements TranslatableInterface
{
    case BV = 'BV'; // bestuursvergadering
    case ALV = 'ALV'; // algemene leden vergadering
    case VV = 'VV'; // voorzitters vergadering
    case VIRT = 'Virt'; // virtual meeting

    /**
     * @return array<array-key, MeetingTypes|string>
     */
    public static function values(): array
    {
        return array_merge(
            array_map(
                static fn (self $status) => $status->value,
                self::cases(),
            ),
            self::cases(),
        );
    }

    /**
     * The meeting type name, deferred so the caller decides on the locale (or takes the source string).
     */
    public function getName(): TranslatableMessage
    {
        return match ($this) {
            self::BV => new TranslatableMessage('BV'),
            self::ALV => new TranslatableMessage('ALV'),
            self::VV => new TranslatableMessage('VV'),
            self::VIRT => new TranslatableMessage('Virt'),
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

    /**
     * The meeting type written out, for the pages that introduce a meeting instead of referring to one.
     */
    public function label(): TranslatableMessage
    {
        return match ($this) {
            self::BV => new TranslatableMessage('Board Meeting'),
            self::ALV => new TranslatableMessage('General Members Meeting'),
            self::VV => new TranslatableMessage('Chair\'s Meeting'),
            self::VIRT => new TranslatableMessage('Virtual Meeting'),
        };
    }

    /**
     * The token used in member-facing URLs, the lowercase English abbreviation.
     */
    public function urlToken(): string
    {
        return match ($this) {
            self::BV => 'bm',
            self::ALV => 'gmm',
            self::VV => 'cm',
            self::VIRT => 'virt',
        };
    }

    /**
     * @return string[]
     */
    public static function getSearchableStrings(): array
    {
        return [
            ...array_column(
                self::cases(),
                'value',
            ),
            'GMM',
            'BM',
            'CM',
        ];
    }

    public static function tryFromSearch(string $input): MeetingTypes
    {
        $value = self::tryFrom($input);

        if (null !== $value) {
            return $value;
        }

        return match ($input) {
            'GMM' => MeetingTypes::ALV,
            'BM' => MeetingTypes::BV,
            'CM' => MeetingTypes::VV,
            'VIRT' => MeetingTypes::VIRT,
            default => throw new InvalidArgumentException('MeetingType is not recognized'),
        };
    }
}
