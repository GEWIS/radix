<?php

declare(strict_types=1);

namespace App\Form\Decision\OrganPage;

use App\Entity\Decision\OrganInformationRevision;
use App\Form\Application\Flow\HasFlowStep;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function trim;

/**
 * What a body writes about itself on one revision of its page. The two images are not here: they are files, which
 * only the controller can store, and they are asked for on the last step so they never travel through the session.
 */
final class OrganPageData
{
    use HasFlowStep;

    public const string STEP_PAGE = 'page';
    public const string STEP_CONTACT = 'contact';
    public const string STEP_IMAGES = 'images';

    /** A card has room for a line or two, and a card that ran on would break the grid it sits in. */
    public const int SHORT_DESCRIPTION_MAXIMUM = 150;

    public const int DESCRIPTION_MAXIMUM = 10000;

    public bool $languageDutch = true;

    public bool $languageEnglish = true;

    #[Assert\Length(
        max: self::SHORT_DESCRIPTION_MAXIMUM,
        groups: [self::STEP_PAGE],
    )]
    public ?string $shortDescriptionNL = null;

    #[Assert\Length(
        max: self::SHORT_DESCRIPTION_MAXIMUM,
        groups: [self::STEP_PAGE],
    )]
    public ?string $shortDescriptionEN = null;

    #[Assert\Length(
        max: self::DESCRIPTION_MAXIMUM,
        groups: [self::STEP_PAGE],
    )]
    public ?string $descriptionNL = null;

    #[Assert\Length(
        max: self::DESCRIPTION_MAXIMUM,
        groups: [self::STEP_PAGE],
    )]
    public ?string $descriptionEN = null;

    #[Assert\Email(groups: [self::STEP_CONTACT])]
    #[Assert\Length(
        max: 255,
        groups: [self::STEP_CONTACT],
    )]
    public ?string $email = null;

    #[Assert\Length(
        max: 255,
        groups: [self::STEP_CONTACT],
    )]
    public ?string $website = null;

    /** @var array<string, string> */
    public array $socialLinks = [];

    public static function fromRevision(OrganInformationRevision $revision): self
    {
        $data = new self();
        $data->shortDescriptionNL = $revision->getShortDescription()->getValueNL();
        $data->shortDescriptionEN = $revision->getShortDescription()->getValueEN();
        $data->descriptionNL = $revision->getDescription()->getValueNL();
        $data->descriptionEN = $revision->getDescription()->getValueEN();
        $data->email = $revision->getEmail();
        $data->website = $revision->getWebsite();
        $data->socialLinks = $revision->getSocialHandles();

        // A body that already wrote something in a language keeps that language on, or opening the form would
        // silently offer to drop half of a page. One nobody has written yet starts with both on.
        $dutch = null !== $data->shortDescriptionNL || null !== $data->descriptionNL;
        $english = null !== $data->shortDescriptionEN || null !== $data->descriptionEN;

        $data->languageDutch = $dutch || !$english;
        $data->languageEnglish = $english || !$dutch;

        return $data;
    }

    public function applyTo(OrganInformationRevision $revision): void
    {
        $revision->getShortDescription()->updateValues(
            $this->languageEnglish ? $this->shortDescriptionEN : $revision->getShortDescription()->getValueEN(),
            $this->languageDutch ? $this->shortDescriptionNL : $revision->getShortDescription()->getValueNL(),
        );
        $revision->getDescription()->updateValues(
            $this->languageEnglish ? $this->descriptionEN : $revision->getDescription()->getValueEN(),
            $this->languageDutch ? $this->descriptionNL : $revision->getDescription()->getValueNL(),
        );
        $revision->setEmail($this->email);
        $revision->setWebsite($this->website);
        $revision->updateSocialLinks($this->socialLinks);
    }

    /**
     * The descriptions are required for each enabled language, and at least one language must be enabled: the
     * per-language requirements are skipped for a language that is off, so with both off a page with no text at all
     * would save.
     */
    #[Assert\Callback(groups: [self::STEP_PAGE])]
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
                    'shortDescriptionNL' => $this->shortDescriptionNL,
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
                'shortDescriptionEN' => $this->shortDescriptionEN,
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
}
