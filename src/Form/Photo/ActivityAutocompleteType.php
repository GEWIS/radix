<?php

declare(strict_types=1);

namespace App\Form\Photo;

use App\Entity\Activity\Activity;
use App\Entity\Application\Enums\Languages;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Activity\ActivityRepository;
use Doctrine\ORM\QueryBuilder;
use Override;
use SortDirection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

use function addcslashes;
use function mb_strtolower;
use function sprintf;
use function Symfony\Component\Translation\t;

/**
 * Picking a publicly visible activity by name, newest first, for linking an album to it.
 *
 * @extends AbstractType<Activity>
 */
#[AsEntityAutocompleteField(alias: 'activity')]
final class ActivityAutocompleteType extends AbstractType
{
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Activity::class,
            'query_builder' => static fn (ActivityRepository $repository): QueryBuilder => $repository
                ->createQueryBuilder('a')
                ->addSelect(
                    'lr',
                    'n',
                )
                ->join(
                    'a.liveRevision',
                    'lr',
                )
                ->join(
                    'lr.name',
                    'n',
                )
                ->andWhere('a.unpublishedAt IS NULL')
                ->orderBy(
                    'lr.beginTime',
                    SortDirection::Descending,
                ),
            'filter_query' => static function (
                QueryBuilder $queryBuilder,
                string $query,
            ): void {
                $queryBuilder->setMaxResults(10);
                if ('' === $query) {
                    return;
                }

                $queryBuilder
                    ->andWhere('LOWER(n.valueEN) LIKE :query OR LOWER(n.valueNL) LIKE :query')
                    ->setParameter(
                        'query',
                        '%' . addcslashes(
                            mb_strtolower($query),
                            '%_\\',
                        ) . '%',
                    );
            },
            'choice_label' => static fn (Activity $activity): string => sprintf(
                '%s (%s)',
                $activity->getName()->getText(Languages::current()) ?? '',
                $activity->getBeginTime()->format('d-m-Y'),
            ),
            'placeholder' => t('Search by activity name'),
            'security' => UserRoles::Board->value,
        ]);
    }

    #[Override]
    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
