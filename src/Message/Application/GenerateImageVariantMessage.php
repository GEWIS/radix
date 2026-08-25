<?php

declare(strict_types=1);

namespace App\Message\Application;

use App\Entity\Application\Enums\ImageVariant;

class GenerateImageVariantMessage
{
    public function __construct(
        private readonly string $sourcePath,
        private readonly ImageVariant $variant,
    ) {
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    public function getVariant(): ImageVariant
    {
        return $this->variant;
    }
}
