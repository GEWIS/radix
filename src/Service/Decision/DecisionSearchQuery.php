<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Database\Enums\MeetingTypes;

/**
 * What {@see DecisionSearchQueryParser} read from a decision search prompt.
 */
final readonly class DecisionSearchQuery
{
    /**
     * @param list<string>      $includeTerms words and phrases the decision text must all contain
     * @param list<string>      $excludeTerms words and phrases the decision text must not contain
     * @param ?MeetingTypes     $type         restricts matches to one meeting type
     * @param ?MeetingReference $meeting      restricts matches to one meeting, point or decision
     * @param ?MeetingReference $reference    a meeting the prompt spells out ("BV 1749"), matched alongside the text
     */
    public function __construct(
        public array $includeTerms,
        public array $excludeTerms,
        public ?MeetingTypes $type,
        public ?MeetingReference $meeting,
        public ?MeetingReference $reference,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->includeTerms
            && [] === $this->excludeTerms
            && null === $this->type
            && null === $this->meeting
            && null === $this->reference;
    }

    /**
     * The meeting the prompt addresses, either by spelling it out or through `type:` and `meeting:` together; null
     * when it addresses none. Its point and decision number are kept, so a prompt naming one decision still says
     * which meeting it belongs to.
     */
    public function namedMeeting(): ?MeetingReference
    {
        if (null !== $this->reference) {
            return $this->reference;
        }

        return $this->meeting?->withType($this->type);
    }
}
