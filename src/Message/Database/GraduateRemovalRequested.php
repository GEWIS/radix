<?php

declare(strict_types=1);

namespace App\Message\Database;

class GraduateRemovalRequested
{
    public function __construct(private readonly int $lidnr)
    {
    }

    public function getLidnr(): int
    {
        return $this->lidnr;
    }
}
