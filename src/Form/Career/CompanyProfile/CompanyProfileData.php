<?php

declare(strict_types=1);

namespace App\Form\Career\CompanyProfile;

use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Form\Application\Flow\HasFlowStep;
use App\Util\Application\SlugRule;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function trim;

/**
 * A company profile as the form asks for it. How a company is identified and whether it appears at all is the
 * board's call, so the step that asks for those is only built for the board and their rules only run there.
 *
 * The two logos are files, which only the controller can store, and are asked for on the last step so they never
 * travel through the session.
 */
final class CompanyProfileData
{
    use HasFlowStep;

    public const string STEP_IDENTITY = 'identity';
    public const string STEP_PROFILE = 'profile';
    public const string STEP_CONTACT = 'contact';
    public const string STEP_LOGO = 'logo';

    #[Assert\NotBlank(
        message: 'Enter a name.',
        groups: [self::STEP_IDENTITY],
    )]
    #[Assert\Length(
        max: 255,
        groups: [self::STEP_IDENTITY],
    )]
    public ?string $name = null;

    #[Assert\NotBlank(
        message: 'Enter a slug.',
        groups: [self::STEP_IDENTITY],
    )]
    #[Assert\Length(
        max: 255,
        groups: [self::STEP_IDENTITY],
    )]
    #[Assert\Regex(
        pattern: SlugRule::PATTERN,
        // phpcs:ignore Generic.Files.LineLength.TooLong -- user-visible strings should not be split
        message: 'A slug starts with a letter and contains only lowercase letters, digits, underscores and hyphens.',
        groups: [self::STEP_IDENTITY],
    )]
    public ?string $slugName = null;

    public bool $published = false;

    public bool $languageDutch = false;

    public bool $languageEnglish = true;

    public ?string $sloganNL = null;

    public ?string $sloganEN = null;

    public ?string $websiteNL = null;

    public ?string $websiteEN = null;

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

    #[Assert\Length(
        max: 255,
        groups: [self::STEP_CONTACT],
    )]
    public ?string $contactAddress = null;

    /** @var array<string, string> */
    public array $socialLinks = [];

    public static function fromCompany(
        Company $company,
        CompanyRevision $revision,
    ): self {
        $data = new self();
        $data->name = '' !== $company->getName()
            ? $company->getName()
            : null;
        $data->slugName = '' !== $company->getSlugName()
            ? $company->getSlugName()
            : null;
        $data->published = $company->isPublished();

        $data->sloganNL = $revision->getSlogan()->getValueNL();
        $data->sloganEN = $revision->getSlogan()->getValueEN();
        $data->websiteNL = $revision->getWebsite()->getValueNL();
        $data->websiteEN = $revision->getWebsite()->getValueEN();
        $data->descriptionNL = $revision->getDescription()->getValueNL();
        $data->descriptionEN = $revision->getDescription()->getValueEN();

        $data->contactName = $revision->getContactName();
        $data->contactEmail = $revision->getContactEmail();
        $data->contactPhone = $revision->getContactPhone();
        $data->contactAddress = $revision->getContactAddress();
        $data->socialLinks = $revision->getSocialHandles();

        // A brand-new company defaults to English enabled, so the form is immediately usable.
        $data->languageDutch = $data->hasContent(true);
        $data->languageEnglish = $data->hasContent(false) || !$data->languageDutch;

        return $data;
    }

    /**
     * `$identity` says whether that step was part of this flow. A company never sees those fields, so writing them
     * back from a data object it could not fill in would blank them.
     */
    public function applyTo(
        Company $company,
        CompanyRevision $revision,
        bool $identity,
    ): void {
        if ($identity) {
            $company->setName((string) $this->name);
            $company->setSlugName((string) $this->slugName);
            $company->setPublished($this->published);
        }

        $revision->getSlogan()->updateValues(
            $this->languageEnglish ? $this->sloganEN : $revision->getSlogan()->getValueEN(),
            $this->languageDutch ? $this->sloganNL : $revision->getSlogan()->getValueNL(),
        );
        $revision->getWebsite()->updateValues(
            $this->languageEnglish ? $this->websiteEN : $revision->getWebsite()->getValueEN(),
            $this->languageDutch ? $this->websiteNL : $revision->getWebsite()->getValueNL(),
        );
        $revision->getDescription()->updateValues(
            $this->languageEnglish ? $this->descriptionEN : $revision->getDescription()->getValueEN(),
            $this->languageDutch ? $this->descriptionNL : $revision->getDescription()->getValueNL(),
        );

        $revision->setContactName($this->contactName);
        $revision->setContactEmail($this->contactEmail);
        $revision->setContactPhone($this->contactPhone);
        $revision->setContactAddress($this->contactAddress);
        $revision->updateSocialLinks($this->socialLinks);
    }

    /**
     * The localised texts are required for each enabled language, and at least one language must be enabled: the
     * per-language requirements are skipped for a language that is off, so with both off a company with no content at
     * all would save.
     */
    #[Assert\Callback(groups: [self::STEP_PROFILE])]
    public function validateLanguages(ExecutionContextInterface $context): void
    {
        if (
            !$this->languageDutch
            && !$this->languageEnglish
        ) {
            $context->buildViolation('At least one language must be used.')
                ->atPath('languageDutch')
                ->addViolation();

            return;
        }

        if ($this->languageDutch) {
            foreach (
                [
                    'sloganNL' => $this->sloganNL,
                    'websiteNL' => $this->websiteNL,
                    'descriptionNL' => $this->descriptionNL,
                ] as $path => $value
            ) {
                if ('' !== trim((string) $value)) {
                    continue;
                }

                $context->buildViolation('Fill in the Dutch text.')
                    ->atPath($path)
                    ->addViolation();
            }
        }

        if (!$this->languageEnglish) {
            return;
        }

        foreach (
            [
                'sloganEN' => $this->sloganEN,
                'websiteEN' => $this->websiteEN,
                'descriptionEN' => $this->descriptionEN,
            ] as $path => $value
        ) {
            if ('' !== trim((string) $value)) {
                continue;
            }

            $context->buildViolation('Fill in the English text.')
                ->atPath($path)
                ->addViolation();
        }
    }

    private function hasContent(bool $dutch): bool
    {
        foreach (
            $dutch
                ? [
                    $this->sloganNL,
                    $this->websiteNL,
                    $this->descriptionNL,
                ]
                : [
                    $this->sloganEN,
                    $this->websiteEN,
                    $this->descriptionEN,
                ] as $value
        ) {
            if ('' !== trim((string) $value)) {
                return true;
            }
        }

        return false;
    }
}
