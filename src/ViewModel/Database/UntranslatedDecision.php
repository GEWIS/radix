<?php

declare(strict_types=1);

namespace App\ViewModel\Database;

use App\Entity\Database\SubDecision\Other;

final readonly class UntranslatedDecision
{
    public function __construct(
        public string $formName,
        public string $meetingType,
        public int $meetingNumber,
        public int $point,
        public int $number,
        public int $sequence,
        public string $content,
    ) {
    }

    public static function fromSubDecision(
        Other $other,
        string $formName,
    ): self {
        return new self(
            $formName,
            $other->getMeetingType()->value,
            $other->getMeetingNumber(),
            $other->getDecisionPoint(),
            $other->getDecisionNumber(),
            $other->getSequence(),
            $other->getContentNL(),
        );
    }
}
