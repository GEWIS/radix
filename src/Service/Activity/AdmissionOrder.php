<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupRole;
use App\Entity\Activity\UserSignup;
use App\Util\Activity\SignupTiers;

use function array_key_exists;
use function array_slice;
use function array_splice;
use function count;
use function in_array;
use function min;
use function usort;

/**
 * Applies a sign-up list's priority modifiers to the pool the draw is about to admit from, as a change to the order
 * the draw admits in, so there is one place where admission is decided.
 *
 * Ranking first, where the coarsest configured order wins and the sort is stable; then the seats held back, the
 * organising committee before the membership tiers; then the role minimums, only to make up a shortfall. All of it
 * acts on the pool that existed at the announced draw moment, never on the latecomers the draw appends afterwards.
 */
final readonly class AdmissionOrder
{
    /**
     * @param list<Signup> $pool the cutoff pool, already in the order the allocation method produced
     *
     * @return list<Signup>
     */
    public function arrange(
        SignupList $list,
        array $pool,
    ): array {
        if (
            [] === $pool
            || !$list->hasPriorityModifiers()
        ) {
            return $pool;
        }

        $ordered = $this->ranked(
            $list,
            $pool,
        );
        $ordered = $this->reserved(
            $list,
            $ordered,
        );

        return $this->guaranteeRoles(
            $list,
            $ordered,
        );
    }

    /**
     * Whoever holds a role the activity cannot go ahead without gets a place, displacing the last person admitted
     * without one. Separate from {@see self::arrange()} because a role is handed out after sign-up has closed, to
     * anybody on the list, so it has to be honoured over the latecomers too and not only over the on-time pool.
     *
     * @param list<Signup> $ordered
     *
     * @return list<Signup>
     */
    public function guaranteeRoles(
        SignupList $list,
        array $ordered,
    ): array {
        if ($list->getRoles()->isEmpty()) {
            return $ordered;
        }

        return $this->rolesFilled(
            $list,
            $ordered,
        );
    }

    /**
     * @param list<Signup> $pool
     *
     * @return list<Signup>
     */
    private function ranked(
        SignupList $list,
        array $pool,
    ): array {
        $ranks = $this->ranks($list);
        if ([] === $ranks) {
            return $pool;
        }

        usort(
            $pool,
            static function (
                Signup $a,
                Signup $b,
            ) use ($ranks): int {
                foreach ($ranks as $rankOf) {
                    $order = $rankOf($a) <=> $rankOf($b);
                    if (0 !== $order) {
                        return $order;
                    }
                }

                return 0;
            },
        );

        return $pool;
    }

    /**
     * @return list<callable(Signup): int>
     */
    private function ranks(SignupList $list): array
    {
        $ranks = [];

        $membership = $list->getMembershipTierOrder();
        if (
            null !== $membership
            && MembershipPriorityMode::Ordering === $list->getMembershipPriorityMode()
        ) {
            $ranks[] = static fn (Signup $signup): int => self::positionIn(
                $membership,
                SignupTiers::membership($signup),
            );
        }

        $program = $list->getProgramTypeOrder();
        if (null !== $program) {
            $ranks[] = static fn (Signup $signup): int => self::positionIn(
                $program,
                SignupTiers::program($signup),
            );
        }

        $cohort = $list->getCohortTierOrder();
        if (null !== $cohort) {
            $ranks[] = static fn (Signup $signup): int => self::positionIn(
                $cohort,
                SignupTiers::cohort($signup),
            );
        }

        return $ranks;
    }

    /**
     * Where a tier sits in an order. A rank may hold several tiers, which are then admitted together.
     *
     * @param list<list<mixed>> $order
     */
    private static function positionIn(
        array $order,
        mixed $tier,
    ): int {
        foreach ($order as $position => $rank) {
            if (
                in_array(
                    $tier,
                    $rank,
                    true,
                )
            ) {
                return $position;
            }
        }

        return count($order);
    }

    /**
     * @param list<Signup> $ordered
     *
     * @return list<Signup>
     */
    private function reserved(
        SignupList $list,
        array $ordered,
    ): array {
        $seats = $list->getMembershipSeats();
        $committeeSeats = $list->getOrganisingCommitteeSeats();
        if (
            [] === $seats
            && null === $committeeSeats
        ) {
            return $ordered;
        }

        $front = [];
        $taken = [];

        if (null !== $committeeSeats) {
            $committee = SignupTiers::organisingCommittee($list);
            $this->take(
                $ordered,
                $front,
                $taken,
                $committeeSeats,
                static function (Signup $signup) use ($committee): bool {
                    return $signup instanceof UserSignup
                        && array_key_exists(
                            $signup->getUser()->getLidnr(),
                            $committee,
                        );
                },
            );
        }

        // Tiers admitted together share the seats held for them: one pool per rank rather than one per tier.
        foreach ($list->getMembershipTierOrder() ?? [] as $rank) {
            $this->take(
                $ordered,
                $front,
                $taken,
                $seats[SignupList::rankKey($rank)] ?? 0,
                static fn (Signup $signup): bool => in_array(
                    SignupTiers::membership($signup),
                    $rank,
                    true,
                ),
            );
        }

        if ([] === $front) {
            return $ordered;
        }

        $rest = [];
        foreach ($ordered as $index => $signup) {
            if (
                array_key_exists(
                    $index,
                    $taken,
                )
            ) {
                continue;
            }

            $rest[] = $signup;
        }

        return [
            ...$front,
            ...$rest,
        ];
    }

    /**
     * @param list<Signup>           $ordered
     * @param list<Signup>           $front
     * @param array<int, true>       $taken   indices of $ordered already spoken for, added to here
     * @param callable(Signup): bool $matches
     */
    private function take(
        array $ordered,
        array &$front,
        array &$taken,
        int $seats,
        callable $matches,
    ): void {
        $remaining = $seats;
        foreach ($ordered as $index => $signup) {
            if ($remaining < 1) {
                return;
            }

            if (
                array_key_exists(
                    $index,
                    $taken,
                )
                || !$matches($signup)
            ) {
                continue;
            }

            $front[] = $signup;
            $taken[$index] = true;
            --$remaining;
        }
    }

    /**
     * @param list<Signup> $ordered
     *
     * @return list<Signup>
     */
    private function rolesFilled(
        SignupList $list,
        array $ordered,
    ): array {
        $capacity = $list->getCapacity();
        if (
            null === $capacity
            || $capacity < 1
            || count($ordered) <= $capacity
        ) {
            return $ordered;
        }

        foreach ($list->getRoles() as $role) {
            $ordered = $this->roleFilled(
                $ordered,
                $role,
                $capacity,
            );
        }

        return $ordered;
    }

    /**
     * @param list<Signup> $ordered
     *
     * @return list<Signup>
     */
    private function roleFilled(
        array $ordered,
        SignupRole $role,
        int $capacity,
    ): array {
        $needed = $role->getMinimum() - $this->holdersAdmitted(
            $ordered,
            $role,
            $capacity,
        );

        while ($needed > 0) {
            $promote = $this->firstWaitlisted(
                $ordered,
                $role,
                $capacity,
            );
            $displace = $this->lastAdmittedWithoutRole(
                $ordered,
                $capacity,
            );
            if (
                null === $promote
                || null === $displace
            ) {
                return $ordered;
            }

            [$signup] = array_splice(
                $ordered,
                $promote,
                1,
            );
            [$displaced] = array_splice(
                $ordered,
                $displace,
                1,
            );
            array_splice(
                $ordered,
                $displace,
                0,
                [$signup],
            );
            array_splice(
                $ordered,
                $capacity,
                0,
                [$displaced],
            );

            --$needed;
        }

        return $ordered;
    }

    /**
     * @param list<Signup> $ordered
     */
    private function holdersAdmitted(
        array $ordered,
        SignupRole $role,
        int $capacity,
    ): int {
        $held = 0;
        foreach (
            array_slice(
                $ordered,
                0,
                $capacity,
            ) as $signup
        ) {
            if ($signup->getRole() !== $role) {
                continue;
            }

            ++$held;
        }

        return $held;
    }

    /**
     * @param list<Signup> $ordered
     */
    private function firstWaitlisted(
        array $ordered,
        SignupRole $role,
        int $capacity,
    ): ?int {
        for ($index = $capacity; $index < count($ordered); ++$index) {
            if ($ordered[$index]->getRole() !== $role) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param list<Signup> $ordered
     */
    private function lastAdmittedWithoutRole(
        array $ordered,
        int $capacity,
    ): ?int {
        for (
            $index = min(
                $capacity,
                count($ordered),
            ) - 1; $index >= 0; --$index
        ) {
            if (null !== $ordered[$index]->getRole()) {
                continue;
            }

            return $index;
        }

        return null;
    }
}
