<?php

declare(strict_types=1);

namespace App\ViewModel\Activity;

use App\Entity\Decision\Organ;

use function sprintf;

/**
 * One entry of the organising-party filter on the activity overview. An abbreviation is reused: a committee is
 * abrogated and years later another is founded under the same letters, and both may have organised activities. The
 * abrogated one is then labelled with the years it existed, so the two entries can be told apart.
 */
final readonly class BodyOption
{
    public function __construct(
        public int $id,
        public string $label,
        public bool $abrogated,
    ) {
    }

    /**
     * @param Organ[] $organs
     *
     * @return list<self>
     */
    public static function fromOrgans(array $organs): array
    {
        $perAbbreviation = [];
        foreach ($organs as $organ) {
            $perAbbreviation[$organ->abbr] = ($perAbbreviation[$organ->abbr] ?? 0) + 1;
        }

        $options = [];
        foreach ($organs as $organ) {
            $abrogated = $organ->isAbrogated();
            $label = $organ->abbr;

            if (
                $abrogated
                && $perAbbreviation[$organ->abbr] > 1
            ) {
                $label .= sprintf(
                    ' (%s - %s)',
                    $organ->foundationDate->format('Y'),
                    $organ->abrogationDate?->format('Y'),
                );
            }

            $options[] = new self(
                (int) $organ->id,
                $label,
                $abrogated,
            );
        }

        return $options;
    }
}
