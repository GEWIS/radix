<?php

declare(strict_types=1);

namespace App\Repository\Application;

use App\Entity\Application\LabelInterface;

interface LabelRepositoryInterface
{
    /**
     * Every label that may still be applied, with its localised name fetch-joined. A retired label is only offered
     * where it is already applied, which is what `$andIds` is for.
     *
     * @param int[] $andIds
     *
     * @return list<LabelInterface>
     */
    public function findActiveWithName(array $andIds = []): array;

    /**
     * Every label with the number of revisions using it, which determines whether it may be removed or only retired.
     *
     * @return list<array{label: LabelInterface, usage: int}>
     */
    public function findAllWithUsage(): array;
}
