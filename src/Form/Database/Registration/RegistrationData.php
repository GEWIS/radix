<?php

declare(strict_types=1);

namespace App\Form\Database\Registration;

use App\Entity\Database\Enums\PostalRegions;
use App\Entity\Database\Enums\Studies;
use App\Entity\Database\MailingList;
use App\Form\Application\Flow\HasFlowStep;
use App\Validator\Database\DeliverableEmailAddress;
use App\Validator\Database\StudentNumber;
use App\Validator\Database\UnusedEmailAddress;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

use function implode;

/**
 * Everything the public sign-up asks for. The flow keeps this in the session while it is being filled in, so nothing
 * here is an entity; the prospective member is built from it once the last step is accepted.
 */
final class RegistrationData
{
    use HasFlowStep;

    public const string STEP_PERSONAL = 'personal';
    public const string STEP_STUDY = 'study';
    public const string STEP_ADDRESS = 'address';
    public const string STEP_LISTS = 'lists';
    public const string STEP_REVIEW = 'review';

    #[Assert\NotBlank(groups: [self::STEP_PERSONAL])]
    #[Assert\Length(
        min: 1,
        max: 16,
        groups: [self::STEP_PERSONAL],
    )]
    public ?string $initials = null;

    #[Assert\NotBlank(groups: [self::STEP_PERSONAL])]
    #[Assert\Length(
        min: 1,
        max: 32,
        groups: [self::STEP_PERSONAL],
    )]
    public ?string $firstName = null;

    // A member without a particle has an empty one, which is shorter than any real particle.
    #[Assert\When(
        expression: "'' !== value",
        constraints: [
            new Assert\Length(
                min: 2,
                max: 32,
            ),
        ],
        groups: [self::STEP_PERSONAL],
    )]
    public string $middleName = '';

    #[Assert\NotBlank(groups: [self::STEP_PERSONAL])]
    #[Assert\Length(
        min: 2,
        max: 32,
        groups: [self::STEP_PERSONAL],
    )]
    public ?string $lastName = null;

    #[Assert\NotBlank(groups: [self::STEP_PERSONAL])]
    #[Assert\Email(groups: [self::STEP_PERSONAL])]
    #[Assert\Regex(
        pattern: '/\.tue\.nl$/i',
        // phpcs:ignore -- user-visible strings should not be split
        message: 'You cannot use your TU/e (student) email address because if you leave or stop studying, we can no longer reach you about important announcements.',
        match: false,
        groups: [self::STEP_PERSONAL],
    )]
    #[DeliverableEmailAddress(groups: [self::STEP_PERSONAL])]
    #[UnusedEmailAddress(groups: [self::STEP_PERSONAL])]
    public ?string $email = null;

    #[Assert\NotNull(groups: [self::STEP_PERSONAL])]
    #[Assert\LessThanOrEqual(
        value: '-10 years',
        message: 'Are you sure that you are younger than 10 years?',
        groups: [self::STEP_PERSONAL],
    )]
    public ?DateTimeImmutable $birth = null;

    #[Assert\NotBlank(groups: [self::STEP_STUDY])]
    #[StudentNumber(groups: [self::STEP_STUDY])]
    public ?string $studentNumber = null;

    #[Assert\NotNull(groups: [self::STEP_STUDY])]
    public ?Studies $study = null;

    #[Assert\NotNull(groups: [self::STEP_ADDRESS])]
    public ?PostalRegions $country = PostalRegions::Netherlands;

    #[Assert\NotBlank(groups: [self::STEP_ADDRESS])]
    #[Assert\Length(
        min: 1,
        max: 32,
        groups: [self::STEP_ADDRESS],
    )]
    public ?string $street = null;

    #[Assert\NotBlank(groups: [self::STEP_ADDRESS])]
    #[Assert\Regex(
        pattern: '/^[1-9]\d*(?:[ \/\-\#\.]?[a-zA-Z0-9]+)?$/',
        groups: [self::STEP_ADDRESS],
    )]
    public ?string $number = null;

    #[Assert\NotBlank(groups: [self::STEP_ADDRESS])]
    #[Assert\Length(
        min: 2,
        max: 16,
        groups: [self::STEP_ADDRESS],
    )]
    public ?string $postalCode = null;

    #[Assert\NotBlank(groups: [self::STEP_ADDRESS])]
    #[Assert\Length(
        min: 1,
        max: 32,
        groups: [self::STEP_ADDRESS],
    )]
    public ?string $city = null;

    public string $phone = '';

    /**
     * Names rather than records: this sits in the session between the steps.
     *
     * @var string[]
     */
    public array $lists = [];

    #[Assert\IsTrue(
        message: 'You cannot become a member of the association without agreeing to the terms.',
        groups: [self::STEP_REVIEW],
    )]
    public bool $agreed = false;

    #[Assert\IsTrue(
        message: 'To pay the membership fee you must accept Stripe\'s privacy policy.',
        groups: [self::STEP_REVIEW],
    )]
    public bool $agreedStripe = false;

    /**
     * @param MailingList[] $mailingLists
     */
    public static function subscribedByDefault(array $mailingLists): self
    {
        $data = new self();

        foreach ($mailingLists as $list) {
            if (!$list->getDefaultSub()) {
                continue;
            }

            $data->lists[] = $list->getName();
        }

        return $data;
    }

    public function getFullName(): string
    {
        $parts = [];

        foreach (
            [
                $this->initials,
                '' !== $this->middleName ? $this->middleName : null,
                $this->lastName,
            ] as $part
        ) {
            if (null === $part) {
                continue;
            }

            $parts[] = $part;
        }

        return implode(
            ' ',
            $parts,
        );
    }
}
