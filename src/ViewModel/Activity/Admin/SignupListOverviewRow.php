<?php

declare(strict_types=1);

namespace App\ViewModel\Activity\Admin;

use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\Languages;
use App\Form\Activity\ActivityFlow\ActivityFlowType;
use App\Form\Activity\Enums\SignupListSection;
use App\Util\Activity\SignupListRule;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;

final readonly class SignupListOverviewRow
{
    /**
     * @param list<string>                                         $chips what the list is, in the
     *                                                                    reader's own language
     * @param list<array{step: string, label: string, done: bool}> $steps the list's own steps, in the
     *                                                                    order they are filled in
     */
    public function __construct(
        public int $id,
        public int $position,
        public string $name,
        public bool $locked,
        public array $chips,
        public array $steps,
    ) {
    }

    /**
     * @param list<Languages> $languages the languages the activity is written in, which a list is named in
     */
    public static function fromSignupList(
        SignupList $list,
        int $position,
        bool $locked,
        array $languages,
        TranslatorInterface $translator,
    ): self {
        $steps = [];
        foreach (SignupListSection::order() as $section) {
            $step = ActivityFlowType::listStep(
                $list,
                $section,
            );

            $steps[] = [
                'step' => $step,
                'label' => $section->trans($translator),
                'done' => $section->isFilledIn(
                    $list,
                    $languages,
                ),
            ];
        }

        return new self(
            id: $list->getId() ?? 0,
            position: $position,
            name: SignupListRule::label(
                $list,
                $position,
                $translator,
            ),
            locked: $locked,
            chips: self::chips(
                $list,
                $translator,
            ),
            steps: $steps,
        );
    }

    /**
     * @return list<string>
     */
    private static function chips(
        SignupList $list,
        TranslatorInterface $translator,
    ): array {
        $chips = [];

        if ($list->getOnlyGEWIS()) {
            $chips[] = $translator->trans('Members only');
        }

        if ($list->getLimitedCapacity()) {
            $capacity = $list->getCapacity();
            $chips[] = null !== $capacity
                ? $translator->trans(
                    'Limited capacity (%capacity%)',
                    ['%capacity%' => $capacity],
                )
                : $translator->trans('Limited capacity');
            $chips[] = $list->getAllocationMethod()->trans($translator);
        }

        $questions = count($list->getFields());
        if ($questions > 0) {
            $chips[] = $translator->trans(
                '%count% question|%count% questions',
                ['%count%' => $questions],
            );
        }

        if ($list->hasPriorityModifiers()) {
            $chips[] = $translator->trans('Priority');
        }

        return $chips;
    }
}
