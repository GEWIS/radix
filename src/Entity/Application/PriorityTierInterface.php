<?php

declare(strict_types=1);

namespace App\Entity\Application;

use BackedEnum;
use Symfony\Contracts\Translation\TranslatableInterface;

interface PriorityTierInterface extends BackedEnum, TranslatableInterface
{
    /**
     * @return list<static>
     */
    public static function defaultOrder(): array;

    /**
     * The categories as ranks, which is the order an organiser starts from: tiers on one rank are admitted together.
     *
     * @return list<list<static>>
     */
    public static function defaultRanks(): array;
}
