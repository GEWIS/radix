<?php

declare(strict_types=1);

namespace App\Repository\Career;

use App\Entity\Career\VacancyLabel;
use App\Repository\Application\FindsLabelsTrait;
use App\Repository\Application\LabelRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VacancyLabel>
 */
class VacancyLabelRepository extends ServiceEntityRepository implements LabelRepositoryInterface
{
    use FindsLabelsTrait;

    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct(
            $registry,
            VacancyLabel::class,
        );
    }
}
