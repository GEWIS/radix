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

        $beginTime = $revision->getBeginTime();
        assert(null !== $beginTime);

        $endTime = $revision->getEndTime();
        assert(null !== $endTime);

        return new ActivityResource(
            id: $id,
            name: $this->text($revision->getName()),
            description: $this->text($revision->getDescription()),
            location: $this->text($revision->getLocation()),
            costs: $this->text($revision->getCosts()),
            beginTime: $beginTime->format(DateTimeInterface::ATOM),
            endTime: $endTime->format(DateTimeInterface::ATOM),
            category: $revision->getCategory(),
            organ: $this->organ($revision),
            company: $this->company($revision),
            requireGEFLITST: $revision->getRequireGEFLITST(),
            requireZettle: $revision->getRequireZettle(),
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
        $organ = $revision->getOrgan();

        if (null === $organ) {
            return null;
        }

        $id = $organ->getId();
        assert(null !== $id);

        return [
            'id' => $id,
            'abbreviation' => $organ->getAbbr(),
            'name' => $organ->getName(),
        ];
    }

    /**
     * @return ActivityApiCompany|null
     */
    private function company(ActivityRevision $revision): ?array
    {
        $company = $revision->getCompany();

        if (null === $company) {
            return null;
        }

        $id = $company->getId();
        assert(null !== $id);

        return [
            'id' => $id,
            'name' => $company->getName(),
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
                'name' => $this->text($label->getName()),
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

            $signupLists[] = [
                'id' => $id,
                'name' => $this->text($signupList->getName()),
                'openDate' => $signupList->getOpenDate()->format(DateTimeInterface::ATOM),
                'closeDate' => $signupList->getCloseDate()->format(DateTimeInterface::ATOM),
                'onlyGEWIS' => $signupList->getOnlyGEWIS(),
                'limitedCapacity' => $signupList->getLimitedCapacity(),
                'capacity' => $signupList->getCapacity(),
            ];
        }

        return $signupLists;
    }
}
