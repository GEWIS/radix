<?php

declare(strict_types=1);

namespace App\Form\Career\VacancyProfile;

use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyLabel;
use App\Entity\Career\VacancyRevision;
use App\Form\Application\Flow\HasFlowStep;
use App\Util\Application\SlugRule;
use App\Validator\Career\ConsistentVacancy;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function array_map;
use function Symfony\Component\Translation\t;
use function trim;

/**
 * A vacancy as the form asks for it. The package and the labels are identifiers rather than records: this sits in
 * the session between the steps, which a detached entity does not survive.
 */
#[ConsistentVacancy(
    closesBeforeOpeningMessage: 'The vacancy cannot close before it opens.',
    outlivesPackageMessage: 'The vacancy cannot stay open past the job package it belongs to.',
    slugTakenMessage: 'Another vacancy of this company already uses this slug in this category.',
    groups: [VacancyData::STEP_GENERAL],
)]
final class VacancyData
{
    use HasFlowStep;

    public const string STEP_GENERAL = 'general';
    public const string STEP_DETAILS = 'details';
    public const string STEP_CONTACT = 'contact';

    /** The vacancy being edited, so its own slug does not read as taken. Null while creating. */
    public ?int $vacancyId = null;

    #[Assert\NotBlank(
        message: 'Enter a slug.',
        groups: [self::STEP_GENERAL],
    )]
    #[Assert\Length(
        max: 255,
        groups: [self::STEP_GENERAL],
    )]
    #[Assert\Regex(
        pattern: SlugRule::PATTERN,
        // phpcs:ignore Generic.Files.LineLength.TooLong -- user-visible strings should not be split
        message: 'A slug starts with a letter and contains only lowercase letters, digits, underscores and hyphens.',
        groups: [self::STEP_GENERAL],
    )]
    public ?string $slugName = null;

    #[Assert\NotBlank(
        message: 'Choose the job package this vacancy belongs to.',
        groups: [self::STEP_GENERAL],
    )]
    public ?int $packageId = null;

    public bool $published = false;

    #[Assert\NotNull(groups: [self::STEP_GENERAL])]
    public ?VacancyCategories $category = null;

    /** @var int[] */
    public array $labelIds = [];

    public ?DateTimeImmutable $startDate = null;

    #[Assert\NotBlank(
        message: 'Enter the day the vacancy closes.',
        groups: [self::STEP_GENERAL],
    )]
    public ?DateTimeImmutable $endDate = null;

    public bool $languageDutch = false;

    public bool $languageEnglish = true;

    public ?string $nameNL = null;

    public ?string $nameEN = null;

    public ?string $locationNL = null;

    public ?string $locationEN = null;

    public ?string $websiteNL = null;

    public ?string $websiteEN = null;

    public ?string $attachmentNL = null;

    public ?string $attachmentEN = null;

    public ?string $descriptionNL = null;

    public ?string $descriptionEN = null;

    #[Assert\Length(
        max: 255,
        groups: [self::STEP_CONTACT],
    )]
    public ?string $contactName = null;

    #[Assert\Email(groups: [self::STEP_CONTACT])]
    #[Assert\Length(
        max: 255,
        groups: [self::STEP_CONTACT],
    )]
    public ?string $contactEmail = null;

    #[Assert\Length(
        max: 255,
        groups: [self::STEP_CONTACT],
    )]
    public ?string $contactPhone = null;

    public static function fromVacancy(
        Vacancy $vacancy,
        VacancyRevision $revision,
    ): self {
        $data = new self();
        $data->vacancyId = $vacancy->getId();
        $data->slugName = $vacancy->getSlugName();
        $data->packageId = $vacancy->getPackage()->getId();
        $data->published = $vacancy->isPublished();

        $data->category = $revision->getCategory();
        $data->labelIds = array_map(
            static fn (VacancyLabel $label): int => (int) $label->getId(),
            $revision->getLabels()->toArray(),
        );
        $data->startDate = null !== $revision->getStartDate()
            ? DateTimeImmutable::createFromInterface($revision->getStartDate())
            : null;
        $data->endDate = null !== $revision->getEndDate()
            ? DateTimeImmutable::createFromInterface($revision->getEndDate())
            : null;

        $data->nameNL = $revision->getName()->getValueNL();
        $data->nameEN = $revision->getName()->getValueEN();
        $data->locationNL = $revision->getLocation()->getValueNL();
        $data->locationEN = $revision->getLocation()->getValueEN();
        $data->websiteNL = $revision->getWebsite()->getValueNL();
        $data->websiteEN = $revision->getWebsite()->getValueEN();
        $data->attachmentNL = $revision->getAttachment()->getValueNL();
        $data->attachmentEN = $revision->getAttachment()->getValueEN();
        $data->descriptionNL = $revision->getDescription()->getValueNL();
        $data->descriptionEN = $revision->getDescription()->getValueEN();

        $data->contactName = $revision->getContactName();
        $data->contactEmail = $revision->getContactEmail();
        $data->contactPhone = $revision->getContactPhone();

        // A brand-new vacancy defaults to English enabled, so the form is immediately usable.
        $data->languageDutch = $data->hasContent(true);
        $data->languageEnglish = $data->hasContent(false) || !$data->languageDutch;

        return $data;
    }

    /**
     * The two texts a language is written in, so a language that is off keeps whatever it already had.
     */
    public function applyTexts(VacancyRevision $revision): void
    {
        foreach (
            [
                'name' => $revision->getName(),
                'location' => $revision->getLocation(),
                'website' => $revision->getWebsite(),
                'attachment' => $revision->getAttachment(),
                'description' => $revision->getDescription(),
            ] as $field => $text
        ) {
            $text->updateValues(
                $this->languageEnglish ? $this->{$field . 'EN'} : $text->getValueEN(),
                $this->languageDutch ? $this->{$field . 'NL'} : $text->getValueNL(),
            );
        }
    }

    /**
     * The localised texts are required for each enabled language, and at least one language must be enabled: the
     * per-language requirements are skipped for a language that is off, so with both off a vacancy with no content at
     * all would save.
     */
    #[Assert\Callback(groups: [self::STEP_DETAILS])]
    public function validateLanguages(ExecutionContextInterface $context): void
    {
        if (
            !$this->languageDutch
            && !$this->languageEnglish
        ) {
            $context->buildViolation(t(
                'At least one language must be used.',
                [],
                'validators',
            )->getMessage())
                ->atPath('languageDutch')
                ->addViolation();

            return;
        }

        foreach (
            [
                'NL' => [
                    $this->languageDutch,
                    'Fill in the Dutch text.',
                ],
                'EN' => [
                    $this->languageEnglish,
                    'Fill in the English text.',
                ],
            ] as $suffix => [$enabled, $message]
        ) {
            if (!$enabled) {
                continue;
            }

            foreach (
                [
                    'name',
                    'location',
                    'website',
                    'description',
                ] as $field
            ) {
                if ('' !== trim((string) $this->{$field . $suffix})) {
                    continue;
                }

                $context->buildViolation($message)
                    ->atPath($field . $suffix)
                    ->addViolation();
            }
        }
    }

    private function hasContent(bool $dutch): bool
    {
        $suffix = $dutch
            ? 'NL'
            : 'EN';

        foreach (
            [
                'name',
                'location',
                'website',
                'description',
            ] as $field
        ) {
            if ('' !== trim((string) $this->{$field . $suffix})) {
                return true;
            }
        }

        return false;
    }
}
