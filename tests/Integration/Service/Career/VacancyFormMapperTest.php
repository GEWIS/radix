<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Career;

use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyLabel;
use App\Entity\Career\VacancyRevision;
use App\Form\Career\VacancyProfile\VacancyData;
use App\Repository\Career\VacancyLabelRepository;
use App\Service\Career\VacancyFormMapper;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

/**
 * The mapper writes what the flow collected onto the vacancy and its working revision. A field the flow did not offer
 * could not have been filled in, so writing it back would blank what is already there, which is the point of the two
 * flags it takes.
 */
final class VacancyFormMapperTest extends DatabaseTestCase
{
    public function testEverythingTheFlowCollectedIsWritten(): void
    {
        $vacancy = $this->vacancy();
        $revision = $this->revision($vacancy);
        $label = $this->label();

        $data = $this->data();
        $data->slugName = 'senior-backend-engineer';
        $data->published = true;
        $data->labelIds = [(int) $label->id];

        $this->mapper()->apply(
            $data,
            $vacancy,
            $revision,
            true,
            true,
        );

        self::assertSame(
            'senior-backend-engineer',
            $vacancy->slugName,
        );
        self::assertTrue($vacancy->published);
        self::assertSame(
            VacancyCategories::ThesisProjects,
            $revision->category,
        );
        self::assertSame(
            'Backend Engineer (senior)',
            $revision->name->getValueEN(),
        );
        self::assertSame(
            'Senior Backend Engineer',
            $revision->name->getValueNL(),
        );
        self::assertSame(
            'Recruitment',
            $revision->contactName,
        );
        self::assertSame(
            'jobs@nexunt.example.com',
            $revision->contactEmail,
        );
        self::assertSame(
            '+31 40 111 1111',
            $revision->contactPhone,
        );
        self::assertSame(
            ['Full-time'],
            $this->labelNames($revision),
        );
    }

    /**
     * Nothing was offered for the identity, so the slug and the package stay exactly as they were.
     */
    public function testTheIdentityAndPublicationAreLeftAloneWhenTheFlowDidNotOfferThem(): void
    {
        $vacancy = $this->vacancy();
        $revision = $this->revision($vacancy);
        $slug = $vacancy->slugName;
        $package = $vacancy->package;
        $published = $vacancy->published;

        $this->mapper()->apply(
            $this->data(),
            $vacancy,
            $revision,
            false,
            false,
        );

        self::assertSame(
            $slug,
            $vacancy->slugName,
        );
        self::assertSame(
            $package,
            $vacancy->package,
        );
        self::assertSame(
            $published,
            $vacancy->published,
        );
    }

    /**
     * A package identifier that no longer resolves (a form left open while the package was removed) may not blank the
     * package the vacancy already belongs to.
     */
    public function testAPackageThatNoLongerExistsLeavesTheBlockInPlace(): void
    {
        $vacancy = $this->vacancy();
        $package = $vacancy->package;

        $data = $this->data();
        $data->packageId = 999999;

        $this->mapper()->apply(
            $data,
            $vacancy,
            $this->revision($vacancy),
            true,
            false,
        );

        self::assertSame(
            $package,
            $vacancy->package,
        );
    }

    /**
     * Identifiers that point at nothing are dropped rather than turning into a null label, and labels that were not
     * selected go.
     */
    public function testTheLabelSelectionReplacesWhatWasThere(): void
    {
        $vacancy = $this->vacancy();
        $revision = $this->revision($vacancy);
        $revision->addLabel($this->label());

        $data = $this->data();
        $data->labelIds = [999999];

        $this->mapper()->apply(
            $data,
            $vacancy,
            $revision,
            false,
            false,
        );

        self::assertSame(
            [],
            $this->labelNames($revision),
        );

        $wanted = $this->label('Hybrid');
        $data->labelIds = [(int) $wanted->id];
        $this->mapper()->apply(
            $data,
            $vacancy,
            $revision,
            false,
            false,
        );

        self::assertSame(
            ['Hybrid'],
            $this->labelNames($revision),
        );
    }

    private function mapper(): VacancyFormMapper
    {
        return self::getContainer()->get(VacancyFormMapper::class);
    }

    private function data(): VacancyData
    {
        $data = new VacancyData();
        $data->category = VacancyCategories::ThesisProjects;
        $data->startDate = new DateTimeImmutable('2030-01-01');
        $data->endDate = new DateTimeImmutable('2030-02-01');
        $data->languageEnglish = true;
        $data->languageDutch = true;
        $data->nameEN = 'Backend Engineer (senior)';
        $data->nameNL = 'Senior Backend Engineer';
        $data->locationEN = 'Eindhoven';
        $data->locationNL = 'Eindhoven';
        $data->descriptionEN = 'A description.';
        $data->descriptionNL = 'Een omschrijving.';
        $data->contactName = 'Recruitment';
        $data->contactEmail = 'jobs@nexunt.example.com';
        $data->contactPhone = '+31 40 111 1111';

        return $data;
    }

    /**
     * @return list<string>
     */
    private function labelNames(VacancyRevision $revision): array
    {
        $names = [];
        foreach ($revision->getLabels() as $label) {
            $names[] = (string) $label->name->getValueEN();
        }

        return $names;
    }

    private function label(string $name = 'Full-time'): VacancyLabel
    {
        foreach (self::getContainer()->get(VacancyLabelRepository::class)->findAll() as $candidate) {
            if ($name === $candidate->name->getValueEN()) {
                return $candidate;
            }
        }

        self::fail('The seed is expected to contain the label "' . $name . '".');
    }

    private function vacancy(): Vacancy
    {
        $vacancy = $this->entityManager->getRepository(Vacancy::class)->findOneBy(['slugName' => 'backend-engineer']);
        self::assertInstanceOf(
            Vacancy::class,
            $vacancy,
        );

        return $vacancy;
    }

    private function revision(Vacancy $vacancy): VacancyRevision
    {
        $revision = $vacancy->getCurrentRevision();
        self::assertInstanceOf(
            VacancyRevision::class,
            $revision,
        );

        return $revision;
    }
}
