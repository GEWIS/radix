<?php

declare(strict_types=1);

namespace App\State\Activity;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Activity\Activity as ActivityResource;
use App\Entity\Activity\Activity as ActivityEntity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Application\LocalisedText;
use App\Repository\Activity\ActivityRepository;
use App\State\Api\CollectionPagination;
use App\Util\Application\QueryValue;
use DateTimeInterface;
use Override;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function iterator_to_array;

/**
 * @phpstan-import-type ActivityApiText from ActivityResource
 * @phpstan-import-type ActivityApiOrgan from ActivityResource
 * @phpstan-import-type ActivityApiCompany from ActivityResource
 * @phpstan-import-type ActivityApiLabel from ActivityResource
 * @phpstan-import-type ActivityApiSignupList from ActivityResource
 * @implements ProviderInterface<ActivityResource>
 */
final readonly class ActivityProvider implements ProviderInterface
{
    private const string SEARCH_LOCALE = 'en';

    public function __construct(
        private ActivityRepository $activityRepository,
        private CollectionPagination $pagination,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    #[Override]
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): object|array|null {
        $request = $context['request'] ?? null;

        return match ($operation->getName()) {
            ActivityResource::OPERATION_COLLECTION => $this->collection(
                $operation,
                $context,
                $request instanceof Request ? $request : null,
            ),
            default => $this->one($uriVariables),
        };
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return iterable<array-key, ActivityResource>
     */
    private function collection(
        Operation $operation,
        array $context,
        ?Request $request,
    ): iterable {
        $window = $this->pagination->window(
            $operation,
            $context,
        );
        $page = $window[0];
        $offset = $window[1];
        $limit = $window[2];

        $organId = QueryValue::number(
            $request,
            'organ',
        );

        $paginator = $this->activityRepository->findForOverview(
            past: QueryValue::isSet(
                $request,
                'past',
            ),
            subscribedBy: null,
            search: '',
            locale: self::SEARCH_LOCALE,
            category: $this->category($request),
            labelIds: [],
            organId: 0 === $organId ? null : $organId,
            openSignupOnly: false,
            from: null,
            until: null,
            limit: $limit,
            offset: $offset,
        );

        $activities = iterator_to_array(
            $paginator->getIterator(),
            false,
        );

        $this->activityRepository->primeLabels($activities);
        $this->activityRepository->primeSignupLists($activities);

        return $this->pagination->paginator(
            $this->resources($activities),
            $page,
            $limit,
            $paginator->count(),
        );
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function one(array $uriVariables): ?ActivityResource
    {
        $id = $uriVariables['id'] ?? null;

        if (null === $id) {
            return null;
        }

        $activity = $this->activityRepository->findPubliclyVisible((int) $id);

        if (null === $activity) {
            return null;
        }

        $this->activityRepository->primeLabels([$activity]);
        $this->activityRepository->primeSignupLists([$activity]);

        return $this->resource($activity);
    }

    private function category(?Request $request): ?ActivityCategories
    {
        if (null === $request) {
            return null;
        }

        return ActivityCategories::tryFrom(QueryValue::text(
            $request,
            'category',
        ));
    }

    /**
     * @param iterable<array-key, ActivityEntity> $activities
     *
     * @return list<ActivityResource>
     */
    private function resources(iterable $activities): array
    {
        $resources = [];

        foreach ($activities as $activity) {
            $resources[] = $this->resource($activity);
        }

        return $resources;
    }

    private function resource(ActivityEntity $activity): ActivityResource
    {
        $id = $activity->getId();
        assert(null !== $id);

        $revision = $activity->getLiveRevision();
        assert(null !== $revision);

        $beginTime = $revision->beginTime;
        assert(null !== $beginTime);

        $endTime = $revision->endTime;
        assert(null !== $endTime);

        return new ActivityResource(
            id: $id,
            name: $this->text($revision->name),
            description: $this->text($revision->description),
            location: $this->text($revision->location),
            costs: $this->text($revision->costs),
            beginTime: $beginTime->format(DateTimeInterface::ATOM),
            endTime: $endTime->format(DateTimeInterface::ATOM),
            category: $revision->category->value,
            organ: $this->organ($revision),
            company: $this->company($revision),
            requireGEFLITST: $revision->requireGEFLITST,
            requireZettle: $revision->requireZettle,
            cancelled: $activity->isCancelled(),
            labels: $this->labels($revision),
            signupLists: $this->signupLists($activity),
        );
    }

    /**
     * @return ActivityApiText
     */
    private function text(LocalisedText $text): array
    {
        return [
            'en' => $text->getValueEN(),
            'nl' => $text->getValueNL(),
        ];
    }

    /**
     * @return ActivityApiOrgan|null
     */
    private function organ(ActivityRevision $revision): ?array
    {
        $organ = $revision->organ;

        if (null === $organ) {
            return null;
        }

        $id = $organ->getId();
        assert(null !== $id);

        return [
            'id' => $id,
            'abbreviation' => $organ->abbr,
            'name' => $organ->name,
        ];
    }

    /**
     * @return ActivityApiCompany|null
     */
    private function company(ActivityRevision $revision): ?array
    {
        $company = $revision->company;

        if (null === $company) {
            return null;
        }

        $id = $company->getId();
        assert(null !== $id);

        return [
            'id' => $id,
            'name' => $company->name,
        ];
    }

    /**
     * @return list<ActivityApiLabel>
     */
    private function labels(ActivityRevision $revision): array
    {
        $labels = [];

        foreach ($revision->getLabels() as $label) {
            $id = $label->getId();
            assert(null !== $id);

            $labels[] = [
                'id' => $id,
                'name' => $this->text($label->name),
            ];
        }

        return $labels;
    }

    /**
     * @return list<ActivityApiSignupList>
     */
    private function signupLists(ActivityEntity $activity): array
    {
        $signupLists = [];

        foreach ($activity->getLiveSignupLists() as $signupList) {
            $id = $signupList->getId();
            assert(null !== $id);

            $openDate = $signupList->openDate;
            $closeDate = $signupList->closeDate;
            assert(null !== $openDate && null !== $closeDate);

            $signupLists[] = [
                'id' => $id,
                'name' => $this->text($signupList->name),
                'openDate' => $openDate->format(DateTimeInterface::ATOM),
                'closeDate' => $closeDate->format(DateTimeInterface::ATOM),
                'onlyGEWIS' => $signupList->onlyGEWIS,
                'limitedCapacity' => $signupList->limitedCapacity,
                'capacity' => $signupList->capacity,
            ];
        }

        return $signupLists;
    }
}
