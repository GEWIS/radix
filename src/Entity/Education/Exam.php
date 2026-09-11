<?php

declare(strict_types=1);

namespace App\Entity\Education;

use App\Entity\Education\Enums\CourseDocumentTypes;
use App\Entity\Education\Enums\ExamTypes;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Override;

/**
 * Exam.
 */
#[Entity]
class Exam extends CourseDocument
{
    /**
     * Type of exam.
     */
    #[Column(
        type: Types::STRING,
        enumType: ExamTypes::class,
    )]
    public ExamTypes $examType;

    #[Override]
    public function getType(): CourseDocumentTypes
    {
        return CourseDocumentTypes::Exam;
    }
}
