<?php

declare(strict_types=1);

namespace App\ApiResource\Decision;

use App\Entity\Database\Enums\OrganTypes;
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
        // No `openapiContext` naming the enum: this DTO is never a schema of its own. The one place a body summary
        // is published is `MemberBody::$body`, which spells the object out inline and names the enum there.
        #[SerializedName('type')]
        public OrganTypes $type,
    ) {
    }
}
