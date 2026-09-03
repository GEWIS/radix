<?php

declare(strict_types=1);

namespace App\Form\Activity\ActivityFlow;

use App\Entity\Activity\ActivityLabel;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Form\Application\Flow\HasFlowStep;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function array_map;
use function Symfony\Component\Translation\t;
use function trim;

/**
 * What the activity form asks for. The organ, the company and the labels are identifiers rather than records: this
 * sits in the session between the steps, which a detached entity does not survive. The sign-up lists are edited on
 * the revision itself, on the last step.
 */
final class ActivityData
{
    use HasFlowStep;

    public const string STEP_GENERAL = 'general';
    public const string STEP_DETAILS = 'details';
    public const string STEP_SIGNUP_LISTS = 'signupLists';

    /**
     * What is chosen when an activity has no organising body or no organising company. Not the same as leaving the
     * question unanswered, which is what the rule below refuses.
     */
    public const string NONE = 'none';

    #[Assert\NotNull(
        message: 'Choose an organising body, or say there is none.',
        groups: [self::STEP_GENERAL],
    )]
    public ?string $organId = null;

    /**
     * Attaching an organising company is C4/board-only. Everybody else is shown the field read-only, so there is
     * nothing for them to answer and the rule below does not apply to them.
     */
    public bool $companyEditable = true;

    #[Assert\When(
        expression: 'this.companyEditable',
        constraints: [
            new Assert\NotNull(message: 'Choose an organising company, or say there is none.'),
        ],
        groups: [self::STEP_GENERAL],
    )]
    public ?string $companyId = null;

    /** True once the activity is live and under way, which is when its start becomes read-only. */
    public bool $scheduleLocked = false;

    #[Assert\NotNull(
        message: 'Enter a start date and time.',
        groups: [self::STEP_GENERAL],
    )]
    public ?DateTimeImmutable $beginTime = null;

    #[Assert\NotNull(
        message: 'Enter an end date and time.',
        groups: [self::STEP_GENERAL],
    )]
    public ?DateTimeImmutable $endTime = null;

    #[Assert\NotNull(
        message: 'Choose a category.',
        groups: [self::STEP_GENERAL],
    )]
    public ?ActivityCategories $category = null;

    /** @var int[] */
    public array $labelIds = [];

    public bool $requireGEFLITST = false;

    public bool $requireZettle = false;

    public bool $languageDutch = false;

    public bool $languageEnglish = true;

    public ?string $nameNL = null;

    public ?string $nameEN = null;

    public ?string $locationNL = null;

    public ?string $locationEN = null;

    public ?string $costsNL = null;

    public ?string $costsEN = null;

    public ?string $descriptionNL = null;

    public ?string $descriptionEN = null;

    /**
     * What the sign-up lists step was last filled in with, kept verbatim. The lists themselves are edited on the
     * revision, and that is built afresh on every request, so this is the only thing about them that survives the
     * step being left and returned to. Null until the step has been visited, which is not the same as it having
     * been emptied.
     *
     * @var array<array-key, mixed>|null
     */
    public ?array $signupListsSubmission = null;

    public static function fromRevision(
        ActivityRevision $revision,
        bool $scheduleLocked,
    ): self {
        $data = new self();
        $data->scheduleLocked = $scheduleLocked;
        // A revision that was saved has answered both questions, so nothing is left unanswered on an edit.
        $data->organId = self::identifier($revision->getOrgan()?->getId());
        $data->companyId = self::identifier($revision->getCompany()?->getId());
        $data->beginTime = null !== $revision->getBeginTime()
            ? DateTimeImmutable::createFromInterface($revision->getBeginTime())
            : null;
        $data->endTime = null !== $revision->getEndTime()
            ? DateTimeImmutable::createFromInterface($revision->getEndTime())
            : null;
        $data->category = $revision->getCategory();
        $data->labelIds = array_map(
            static fn (ActivityLabel $label): int => (int) $label->getId(),
            $revision->getLabels()->toArray(),
        );
        $data->requireGEFLITST = $revision->getRequireGEFLITST();
        $data->requireZettle = $revision->getRequireZettle();

        $data->nameNL = $revision->getName()->getValueNL();
        $data->nameEN = $revision->getName()->getValueEN();
        $data->locationNL = $revision->getLocation()->getValueNL();
        $data->locationEN = $revision->getLocation()->getValueEN();
        $data->costsNL = $revision->getCosts()->getValueNL();
        $data->costsEN = $revision->getCosts()->getValueEN();
        $data->descriptionNL = $revision->getDescription()->getValueNL();
        $data->descriptionEN = $revision->getDescription()->getValueEN();

        // A brand-new activity defaults to English enabled, so the form is immediately usable.
        $data->languageDutch = $data->hasContent(true);
        $data->languageEnglish = $data->hasContent(false) || !$data->languageDutch;

        return $data;
    }

    private static function identifier(?int $id): string
    {
        return null === $id
            ? self::NONE
            : (string) $id;
    }

    /**
     * The four texts a language is written in, so a language that is off keeps whatever it already had.
     */
    public function applyTexts(ActivityRevision $revision): void
    {
        foreach (
            [
                'name' => $revision->getName(),
                'location' => $revision->getLocation(),
                'costs' => $revision->getCosts(),
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
     * Once the activity is under way its start is locked and no longer constrains anything, so the end is held to the
     * present instead: without that it could be moved into the past, which would make the activity immutable.
     */
    #[Assert\Callback(groups: [self::STEP_GENERAL])]
    public function validateSchedule(ExecutionContextInterface $context): void
    {
        $now = new DateTimeImmutable();

        if (!$this->scheduleLocked) {
            if (
                null !== $this->beginTime
                && $this->beginTime <= $now
            ) {
                $context->buildViolation(t(
                    'The activity must start in the future.',
                    [],
                    'validators',
                )->getMessage())
                    ->atPath('beginTime')
                    ->addViolation();
            }

            if (
                null !== $this->beginTime
                && null !== $this->endTime
                && $this->endTime <= $this->beginTime
            ) {
                $context->buildViolation(t(
                    'The end time must be after the start time.',
                    [],
                    'validators',
                )->getMessage())
                    ->atPath('endTime')
                    ->addViolation();
            }

            return;
        }

        if (
            null === $this->endTime
            || $this->endTime > $now
        ) {
            return;
        }

        $context->buildViolation(t(
            'The end time must be in the future.',
            [],
            'validators',
        )->getMessage())
            ->atPath('endTime')
            ->addViolation();
    }

    /**
     * The localised texts are required for each enabled language, and at least one language must be enabled: the
     * per-language requirements are skipped for a language that is off, so with both off an activity with no content
     * at all would save.
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
                    'costs',
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
                'costs',
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
