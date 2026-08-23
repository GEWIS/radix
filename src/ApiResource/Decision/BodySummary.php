<?php

declare(strict_types=1);

namespace App\ApiResource\Decision;

use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class BodySummary
{
    public function __construct(
        #[SerializedName('id')]
        public int $id,
        #[SerializedName('abbreviation')]
        public string $abbreviation,
        #[SerializedName('name')]
        public string $name,
        #[SerializedName('type')]
        public string $type,
    ) {
    }
}
