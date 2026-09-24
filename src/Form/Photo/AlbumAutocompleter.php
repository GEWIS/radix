<?php

declare(strict_types=1);

namespace App\Form\Photo;

use App\Entity\Photo\Album;
use App\Entity\User\Enums\UserRoles;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Override;
use SortDirection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\Autocomplete\EntityAutocompleterInterface;

use function addcslashes;

/**
 * Album search for the move-photos destination picker, which is not a Symfony form. Any album may be a destination,
 * drafts and sub-albums included, except the one the photos are already in, which the page passes as `exclude`.
 *
 * @implements EntityAutocompleterInterface<Album>
 */
#[AutoconfigureTag(
    name: 'ux.entity_autocompleter',
    attributes: ['alias' => 'album'],
)]
final readonly class AlbumAutocompleter implements EntityAutocompleterInterface
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    #[Override]
    public function getEntityClass(): string
    {
        return Album::class;
    }

    #[Override]
    public function createFilteredQueryBuilder(
        EntityRepository $repository,
        string $query,
    ): QueryBuilder {
        $queryBuilder = $repository->createQueryBuilder('a')
            ->leftJoin(
                'a.parent',
                'parent',
            )
            ->addSelect('parent')
            ->andWhere('a.name LIKE :query')
            ->setParameter(
                'query',
                '%' . addcslashes(
                    $query,
                    '%_\\',
                ) . '%',
            )
            ->orderBy(
                'a.name',
                SortDirection::Ascending,
            )
            ->setMaxResults(25);

        $exclude = $this->requestStack->getCurrentRequest()?->query->getInt('exclude') ?? 0;
        if (0 !== $exclude) {
            $queryBuilder
                ->andWhere('a.id != :exclude')
                ->setParameter(
                    'exclude',
                    $exclude,
                );
        }

        return $queryBuilder;
    }

    #[Override]
    public function getLabel(object $entity): string
    {
        $parent = $entity->getParent();

        return null === $parent
            ? $entity->name
            : $parent->name . ' / ' . $entity->name;
    }

    #[Override]
    public function getValue(object $entity): mixed
    {
        return $entity->id;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getAttributes(object $entity): array
    {
        return [];
    }

    #[Override]
    public function isGranted(Security $security): bool
    {
        return $security->isGranted(UserRoles::Board->value);
    }

    #[Override]
    public function getGroupBy(): mixed
    {
        return null;
    }

    public function getTranslationDomain(): null
    {
        return null;
    }
}
