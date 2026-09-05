<?php

declare(strict_types=1);

namespace App\Util\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\Languages;
use App\Form\Activity\Enums\SignupListSection;
use Symfony\Contracts\Translation\TranslatorInterface;

use function trim;

/**
 * The single definition of "this sign-up list has not been filled in yet", shared by the form that refuses to save
 * such a list, the workflow guard that withholds submitting and approving it and the review screen that explains
 * the block.
 */
final class SignupListRule
{
    /**
     * @return array{list: SignupList, position: int, section: SignupListSection}|null
     */
    public static function firstUnfinished(ActivityRevision $revision): ?array
    {
        $position = 0;
        foreach ($revision->getSignupLists() as $list) {
            ++$position;
            foreach (SignupListSection::order() as $section) {
                if ($section->isFilledIn($list)) {
                    continue;
                }

                return [
                    'list' => $list,
                    'position' => $position,
                    'section' => $section,
                ];
            }
        }

        return null;
    }

    public static function label(
        SignupList $list,
        int $position,
        TranslatorInterface $translator,
    ): string {
        $name = trim($list->getName()->getText(Languages::current()) ?? '');

        if ('' !== $name) {
            return $name;
        }

        return $translator->trans(
            'Sign-up list %position%',
            ['%position%' => $position],
        );
    }
}
