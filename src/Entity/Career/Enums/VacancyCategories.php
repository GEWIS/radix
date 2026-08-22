<?php

declare(strict_types=1);

namespace App\Entity\Career\Enums;

use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Enum for the (single, mandatory) category of a vacancy.
 *
 * These used to be free-form {@see \App\Entity\Career\VacancyCategory} database rows, but the set is small and stable
 * enough to hard-code (GH-2068). The backing value doubles as the URL slug.
 */
enum VacancyCategories: string implements TranslatableInterface
{
    case Jobs = 'jobs';
    case Internships = 'internships';
    case Traineeships = 'traineeships';
    case StudentJobs = 'student-jobs';
    case ThesisProjects = 'thesis-projects';

    /**
     * The singular, human-readable name of the category (e.g. shown on a single vacancy).
     */
    public function label(): TranslatableMessage
    {
        return match ($this) {
            self::Jobs => new TranslatableMessage('Job'),
            self::Internships => new TranslatableMessage('Internship'),
            self::Traineeships => new TranslatableMessage('Traineeship'),
            self::StudentJobs => new TranslatableMessage('Student job'),
            self::ThesisProjects => new TranslatableMessage('Thesis project'),
        };
    }

    /**
     * The singular name is what a category is called when it stands on its own, so it is also what a select renders
     * for the category field.
     */
    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return $this->label()->trans(
            $translator,
            $locale,
        );
    }

    /**
     * The plural, human-readable name of the category (e.g. shown in the menu and as a listing heading).
     */
    public function pluralLabel(): TranslatableMessage
    {
        return match ($this) {
            self::Jobs => new TranslatableMessage('Jobs'),
            self::Internships => new TranslatableMessage('Internships'),
            self::Traineeships => new TranslatableMessage('Traineeships'),
            self::StudentJobs => new TranslatableMessage('Student jobs'),
            self::ThesisProjects => new TranslatableMessage('Thesis projects'),
        };
    }

    /**
     * The `.badge-*` modifier used to colour this category's badge. Each category gets its own hue (mapped onto the
     * existing subtle theme-colour badges, so both light and dark modes are handled) so the categories are visually
     * distinguishable at a glance across the vacancy listings and detail pages.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Jobs => 'badge-gewis-primary',
            self::Internships => 'badge-info',
            self::Traineeships => 'badge-success',
            self::StudentJobs => 'badge-warning',
            self::ThesisProjects => 'badge-secondary',
        };
    }
}
