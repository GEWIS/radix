<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Decision\BoardMember;
use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganMember;
use App\Repository\Decision\MemberRepository;
use DateTime;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_values;
use function usort;

/**
 * @phpstan-type OrganMembershipType = array{
 *     organ: Organ,
 *     functions: list<string>,
 * }
 * @phpstan-type BoardMembershipType = array{
 *     function: string,
 *     installDate: DateTime,
 *     releaseDate: DateTime|null,
 * }
 */
class MemberInfoService
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Group a member's organ installations into the organs they are currently part of and those they used to be part
     * of, each with the functions (other than plain membership) they held there.
     *
     * @return array{
     *     current: list<OrganMembershipType>,
     *     historical: list<OrganMembershipType>,
     * }
     */
    public function getOrganMemberships(Member $member): array
    {
        return [
            'current' => $this->groupByOrgan($this->memberRepository->findCurrentInstallations($member)),
            'historical' => $this->groupByOrgan($this->memberRepository->findHistoricalInstallations($member)),
        ];
    }

    /**
     * @return array{
     *     current: list<BoardMembershipType>,
     *     historical: list<BoardMembershipType>,
     * }
     */
    public function getBoardMemberships(Member $member): array
    {
        $installations = $member->getBoardInstallations()->toArray();
        usort(
            $installations,
            static fn (BoardMember $a, BoardMember $b): int => $b->getInstallDate() <=> $a->getInstallDate(),
        );

        $current = [];
        $historical = [];

        foreach ($installations as $installation) {
            $entry = [
                'function' => $installation->getFunction()->trans($this->translator),
                'installDate' => $installation->getInstallDate(),
                'releaseDate' => $installation->getReleaseDate(),
            ];

            if ($member->isCurrentBoard($installation)) {
                $current[] = $entry;
            } else {
                $historical[] = $entry;
            }
        }

        return [
            'current' => $current,
            'historical' => $historical,
        ];
    }

    /**
     * @param OrganMember[] $installations
     *
     * @return list<OrganMembershipType>
     */
    private function groupByOrgan(array $installations): array
    {
        $organs = [];

        foreach ($installations as $installation) {
            $organ = $installation->getOrgan();
            $abbreviation = $organ->getAbbr();

            if (!isset($organs[$abbreviation])) {
                $organs[$abbreviation] = [
                    'organ' => $organ,
                    'functions' => [],
                ];
            }

            $function = $installation->getFunction();
            if ($function->isAdministrative()) {
                continue;
            }

            $organs[$abbreviation]['functions'][] = $function->trans($this->translator);
        }

        return array_values($organs);
    }
}
