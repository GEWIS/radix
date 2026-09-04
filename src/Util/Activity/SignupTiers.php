<?php

declare(strict_types=1);

namespace App\Util\Activity;

use App\Entity\Activity\Enums\CohortTier;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\AssociationYear;
use App\Entity\Application\PriorityTierInterface;
use App\Entity\Database\Enums\ProgramType;
use DateTime;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function implode;

/**
 * Which category a sign-up falls in for each of the things a sign-up list can rank on. In one place because the
 * draw and the sign-ups page that explains it afterwards must answer this identically, and because every one of
 * them has to have an answer for an external sign-up, who has no member behind them at all.
 */
final class SignupTiers
{
    /**
     * An order as it is read back to somebody: the ranks in turn, the tiers admitted together on one of them named
     * beside each other.
     *
     * @param ?list<list<PriorityTierInterface>> $order
     */
    public static function orderText(
        ?array $order,
        TranslatorInterface $translator,
    ): ?string {
        if (null === $order) {
            return null;
        }

        return implode(
            ' > ',
            array_map(
                static fn (array $rank): string => implode(
                    ' & ',
                    array_map(
                        static fn (PriorityTierInterface $tier): string => $tier->trans($translator),
                        $rank,
                    ),
                ),
                $order,
            ),
        );
    }

    /**
     * Every order a list admits in, as they are read back to somebody, in the order the list applies them.
     *
     * @return list<string>
     */
    public static function orderTexts(
        SignupList $list,
        TranslatorInterface $translator,
    ): array {
        $texts = [];
        foreach (
            [
                $list->getMembershipTierOrder(),
                $list->getProgramTypeOrder(),
                $list->getCohortTierOrder(),
            ] as $order
        ) {
            $text = self::orderText(
                $order,
                $translator,
            );

            if (null === $text) {
                continue;
            }

            $texts[] = $text;
        }

        return $texts;
    }

    /**
     * The current members of the body organising the activity, by membership number, or nobody when no body does.
     *
     * @return array<int, true>
     */
    public static function organisingCommittee(SignupList $list): array
    {
        $organ = $list->getActivity()->getOrgan();
        if (null === $organ) {
            return [];
        }

        $members = [];
        foreach ($organ->getMembers() as $organMember) {
            if (!$organMember->isCurrent()) {
                continue;
            }

            $members[$organMember->getMember()->getLidnr()] = true;
        }

        return $members;
    }

    public static function membership(Signup $signup): MembershipTier
    {
        if (!$signup instanceof UserSignup) {
            return MembershipTier::NonMember;
        }

        return MembershipTier::of($signup->getUser()->getType());
    }

    public static function cohort(Signup $signup): CohortTier
    {
        if (!$signup instanceof UserSignup) {
            return CohortTier::Unknown;
        }

        $generation = $signup->getUser()->getGeneration();
        if ($generation < 1) {
            return CohortTier::Unknown;
        }

        return match (AssociationYear::fromDate(new DateTime())->getYear() - $generation) {
            0 => CohortTier::FirstYear,
            1 => CohortTier::SecondYear,
            default => CohortTier::Senior,
        };
    }

    public static function program(Signup $signup): ProgramType
    {
        if (!$signup instanceof UserSignup) {
            return ProgramType::Other;
        }

        return $signup->getUser()->getStudy()->getProgramType();
    }
}
