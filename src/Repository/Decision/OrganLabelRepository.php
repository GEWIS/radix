<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Decision\OrganLabel;
use App\Repository\Application\FindsLabelsTrait;
use App\Repository\Application\LabelRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganLabel>
 */
class OrganLabelRepository extends ServiceEntityRepository implements LabelRepositoryInterface
{
    use FindsLabelsTrait;

    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct(
            $registry,
            OrganLabel::class,
        );
    }
}
