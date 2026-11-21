<?php

declare(strict_types=1);

namespace App\Tests\Form\Career;

use App\Entity\Career\CareerLocalisedText;
use App\Entity\Career\CompanyJobPackage;
use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Form\Career\VacancyProfile\VacancyData;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Reading a vacancy's working revision back into the form: which fields are on the aggregate rather than the revision,
 * and how a vacancy that is only written in one language decides which toggle starts out on.
 */
final class VacancyDataTest extends TestCase
{
    public function testTheWorkingRevisionIsReadBackInFull(): void
    {
        $data = VacancyData::fromVacancy(
            $this->vacancy(),
            $this->revision(
                'Backend Engineer',
                'Backend Engineer',
            ),
        );

        self::assertSame(
            VacancyCategories::Jobs,
            $data->category,
        );
        self::assertSame(
            '2027-01-31',
            $data->endDate?->format('Y-m-d'),
        );
        self::assertSame(
            'Backend Engineer',
            $data->nameEN,
        );
        self::assertSame(
            'https://example.com/backend',
            $data->websiteEN,
        );
        self::assertSame(
            'Rewritten description',
            $data->descriptionEN,
        );
        self::assertSame(
            'Ilse',
            $data->contactName,
        );
        self::assertTrue($data->languageDutch);
        self::assertTrue($data->languageEnglish);
    }

    /**
     * A vacancy written only in Dutch must not come back with the English toggle on, or the form would demand an
     * English title the company never had.
     */
    public function testADutchOnlyVacancyStartsWithTheEnglishToggleOff(): void
    {
        $data = VacancyData::fromVacancy(
            $this->vacancy(),
            $this->revision(
                '',
                'Backend Engineer',
            ),
        );

        self::assertTrue($data->languageDutch);
        self::assertFalse($data->languageEnglish);
    }

    /**
     * A vacancy with no text at all still has to offer a language to fill in, or the form cannot be submitted.
     */
    public function testAnEmptyVacancyDefaultsToEnglish(): void
    {
        $data = VacancyData::fromVacancy(
            $this->vacancy(),
            $this->revision(
                '',
                '',
            ),
        );

        self::assertFalse($data->languageDutch);
        self::assertTrue($data->languageEnglish);
    }

    private function vacancy(): Vacancy
    {
        $vacancy = new Vacancy();
        $vacancy->slugName = 'backend-engineer';
        $vacancy->published = true;
        $vacancy->package = new CompanyJobPackage();

        return $vacancy;
    }

    private function revision(
        string $english,
        string $dutch,
    ): VacancyRevision {
        $revision = new VacancyRevision();
        $revision->category = VacancyCategories::Jobs;
        $revision->startDate = new DateTimeImmutable('2026-06-01');
        $revision->endDate = new DateTimeImmutable('2027-01-31');
        $revision->name = new CareerLocalisedText(
            $english,
            $dutch,
        );
        $revision->location = new CareerLocalisedText(
            $english,
            $dutch,
        );
        $revision->website = new CareerLocalisedText(
            '' !== $english ? 'https://example.com/backend' : '',
            '' !== $dutch ? 'https://example.com/backend' : '',
        );
        $revision->description = new CareerLocalisedText(
            '' !== $english ? 'Rewritten description' : '',
            '' !== $dutch ? 'Herschreven omschrijving' : '',
        );
        $revision->contactName = 'Ilse';
        $revision->contactEmail = 'ilse@nexunt.example.com';
        $revision->contactPhone = '+31 40 000 0000';

        return $revision;
    }
}
