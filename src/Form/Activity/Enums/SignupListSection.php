<?php

declare(strict_types=1);

namespace App\Form\Activity\Enums;

use App\Entity\Activity\SignupList;
use Override;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;
use function trim;

enum SignupListSection: string implements TranslatableInterface
{
    case Basics = 'basics';
    case Allocation = 'allocation';
    case Questions = 'questions';

    /**
     * @return list<self>
     */
    public static function order(): array
    {
        return [
            self::Basics,
            self::Allocation,
            self::Questions,
        ];
    }

    /**
     * The one address a list's section has, as the step the form edits it on and the section the review shows it in.
     */
    public function keyFor(SignupList $list): string
    {
        return sprintf(
            'list:%s:%s',
            $list->getLineageId()->toRfc4122(),
            $this->value,
        );
    }

    public function isFilledIn(SignupList $list): bool
    {
        return match ($this) {
            self::Basics => (
                '' !== trim($list->getName()->getValueEN() ?? '')
                || '' !== trim($list->getName()->getValueNL() ?? '')
            )
                && null !== $list->getOpenDate()
                && null !== $list->getCloseDate(),
            self::Allocation => !$list->getLimitedCapacity()
                || ($list->getCapacity() ?? 0) >= 1,
            self::Questions => true,
        };
    }

    public function description(TranslatorInterface $translator): string
    {
        return match ($this) {
            self::Basics => $translator->trans(
                'Name the list and say when it is open. Everything else about this list comes later.',
            ),
            self::Allocation => $translator->trans('Who gets a place when there are more sign-ups than seats.'),
            self::Questions => $translator->trans('What people are asked when they sign up for this list.'),
        };
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return match ($this) {
            self::Basics => $translator->trans(
                'Basics',
                locale: $locale,
            ),
            self::Allocation => $translator->trans(
                'Allocation',
                locale: $locale,
            ),
            self::Questions => $translator->trans(
                'Questions',
                locale: $locale,
            ),
        };
    }
}
