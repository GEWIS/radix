<?php

declare(strict_types=1);

namespace App\Repository\Activity;

use App\Entity\Activity\ActivityLabel;
use App\Repository\Application\FindsLabelsTrait;
use App\Repository\Application\LabelRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLabel>
 */
class ActivityLabelRepository extends ServiceEntityRepository implements LabelRepositoryInterface
{
    use FindsLabelsTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            ActivityLabel::class,
        );
    }
}
