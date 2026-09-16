<?php

declare(strict_types=1);

namespace App\Form\Decision\OrganPage;

use App\Entity\Decision\OrganInformationRevision;
use App\Form\Application\Flow\HasFlowStep;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function Symfony\Component\Translation\t;
use function trim;

/**
 * What a body writes about itself on one revision of its page. The two images are not here: they are files, which only
 * the controller can store, and they are collected on the last step so they are never passed through the session.
 */
final class OrganPageData
{
    use HasFlowStep;

    public const string STEP_PAGE = 'page';
    public const string STEP_CONTACT = 'contact';
    public const string STEP_IMAGES = 'images';

    /** A card has room for a line or two, and a card that ran on would break the grid it is in. */
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
        $data->shortDescriptionNL = $revision->shortDescription->getValueNL();
        $data->shortDescriptionEN = $revision->shortDescription->getValueEN();
        $data->descriptionNL = $revision->description->getValueNL();
        $data->descriptionEN = $revision->description->getValueEN();
        $data->email = $revision->email;
        $data->website = $revision->website;
        $data->socialLinks = $revision->getSocialHandles();

        // A body that already wrote something in a language keeps that language on, or opening the form would
        // silently offer to drop half of a page. A page that has not been written yet starts with both on.
        $dutch = null !== $data->shortDescriptionNL || null !== $data->descriptionNL;
        $english = null !== $data->shortDescriptionEN || null !== $data->descriptionEN;

        $data->languageDutch = $dutch || !$english;
        $data->languageEnglish = $english || !$dutch;

        return $data;
    }

    public function applyTo(OrganInformationRevision $revision): void
    {
        $revision->shortDescription->updateValues(
            $this->languageEnglish ? $this->shortDescriptionEN : $revision->shortDescription->getValueEN(),
            $this->languageDutch ? $this->shortDescriptionNL : $revision->shortDescription->getValueNL(),
        );
        $revision->description->updateValues(
            $this->languageEnglish ? $this->descriptionEN : $revision->description->getValueEN(),
            $this->languageDutch ? $this->descriptionNL : $revision->description->getValueNL(),
        );
        $revision->email = $this->email;
        $revision->website = $this->website;
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
            $context->buildViolation(t(
                'At least one language must be used.',
                [],
                'validators',
            )->getMessage())
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

                $context->buildViolation(t(
                    'Fill in the Dutch text.',
                    [],
                    'validators',
                )->getMessage())
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

            $context->buildViolation(t(
                'Fill in the English text.',
                [],
                'validators',
            )->getMessage())
                ->atPath($path)
                ->addViolation();
        }
    }
}
