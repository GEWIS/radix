<?php

declare(strict_types=1);

namespace App\Twig\Components\Decision;

use App\Entity\Decision\Decision;
use App\Entity\Decision\Meeting;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Decision\DecisionRepository;
use App\Service\Decision\DecisionSearchQuery;
use App\Service\Decision\DecisionSearchQueryParser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

use function array_values;
use function count;
use function mb_strlen;
use function spl_object_id;
use function trim;

#[AsLiveComponent(
    name: 'Decision:DecisionSearch',
    template: 'components/Decision/DecisionSearch.html.twig',
)]
#[IsGranted(UserRoles::User->value)]
final class DecisionSearch
{
    use DefaultActionTrait;

    /**
     * {@see DecisionRepository::search()} caps its results; surfaced in the UI when hit.
     */
    public const int RESULT_CAP = 100;

    private const int MIN_QUERY_LENGTH = 2;

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public string $q = '';

    /** @var list<Decision>|null */
    private ?array $results = null;

    /**
     * The virtual counterparts of the results, keyed as {@see DecisionRepository::key()} addresses them.
     *
     * @var array<string, list<Decision>>|null
     */
    private ?array $virtualCounterparts = null;

    private ?DecisionSearchQuery $parsedQuery = null;

    public function __construct(
        private readonly DecisionRepository $decisionRepository,
        private readonly DecisionSearchQueryParser $queryParser,
    ) {
    }

    public function hasQuery(): bool
    {
        return mb_strlen(trim($this->q)) >= self::MIN_QUERY_LENGTH;
    }

    /**
     * The results grouped per meeting, in the order the search returned them (newest meeting first).
     *
     * @return list<array{meeting: Meeting, decisions: list<Decision>}>
     */
    public function getResultsByMeeting(): array
    {
        $groups = [];
        foreach ($this->getResults() as $decision) {
            $meeting = $decision->getMeeting();
            $key = spl_object_id($meeting);

            $groups[$key] ??= [
                'meeting' => $meeting,
                'decisions' => [],
            ];
            $groups[$key]['decisions'][] = $decision;
        }

        return array_values($groups);
    }

    /**
     * The virtual decisions that are this one's counterpart.
     *
     * A virtual meeting says again what a real meeting decided, and the search answers with the real decision rather
     * than with both. What the virtual meeting made of it is still worth reaching, so it hangs off the decision it
     * belongs to instead of standing beside it.
     *
     * @return list<Decision>
     */
    public function getVirtualCounterpartsOf(Decision $decision): array
    {
        $this->virtualCounterparts ??= $this->decisionRepository->findVirtualCounterpartsOf($this->getResults());

        return $this->virtualCounterparts[DecisionRepository::key($decision)] ?? [];
    }

    public function getResultCount(): int
    {
        return count($this->getResults());
    }

    public function isCapped(): bool
    {
        return $this->getResultCount() >= self::RESULT_CAP;
    }

    /**
     * The terms to mark in the rendered results: everything that must appear in the text.
     *
     * @return list<string>
     */
    public function getHighlightTerms(): array
    {
        return $this->getParsedQuery()->includeTerms;
    }

    /**
     * @return list<Decision>
     */
    private function getResults(): array
    {
        if (!$this->hasQuery()) {
            return [];
        }

        return $this->results ??= array_values($this->decisionRepository->search($this->getParsedQuery()));
    }

    private function getParsedQuery(): DecisionSearchQuery
    {
        return $this->parsedQuery ??= $this->queryParser->parse(trim($this->q));
    }
}
