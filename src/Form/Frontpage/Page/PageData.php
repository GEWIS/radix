<?php

declare(strict_types=1);

namespace App\Form\Frontpage\Page;

use App\Entity\Application\Enums\Languages;
use App\Entity\Frontpage\Page;
use App\Entity\User\Enums\UserRoles;
use App\Form\Application\Flow\HasFlowStep;
use App\Util\Application\SlugRule;
use App\Validator\Frontpage\UnclaimedPageAddress;
use Symfony\Component\Validator\Constraints as Assert;

use function trim;

/**
 * What the page form asks. Every field is kept as its two halves rather than as a localised text, so each language
 * can carry its own rule: a page is addressed by its own words, and the two addresses are checked separately.
 */
#[UnclaimedPageAddress(
    reservedMessage: 'The website already answers to this address, so a page cannot take it.',
    takenMessage: 'Another page already answers to this address.',
    groups: [PageData::STEP_ADDRESS],
)]
final class PageData
{
    use HasFlowStep;

    public const string STEP_ADDRESS = 'address';
    public const string STEP_CONTENT = 'content';

    private const string SLUG_MESSAGE = 'Use three to thirty-two lower-case letters, digits, '
        . 'underscores or hyphens, starting with a letter.';

    /** The page being edited, so its own address does not read as taken. Null while creating. */
    public ?int $pageId = null;

    public UserRoles $requiredRole = UserRoles::Guest;

    // Each part of the address is a segment of a public URL, so it is held to what a slug may look like. An empty one
    // is how a page higher up the tree says it has no sub-category or name, which is why the rule only applies to
    // what was actually written.
    #[Assert\Regex(
        pattern: SlugRule::BOUNDED_PATTERN,
        message: self::SLUG_MESSAGE,
        groups: [self::STEP_ADDRESS],
    )]
    public ?string $categoryNL = null;

    #[Assert\Regex(
        pattern: SlugRule::BOUNDED_PATTERN,
        message: self::SLUG_MESSAGE,
        groups: [self::STEP_ADDRESS],
    )]
    public ?string $categoryEN = null;

    #[Assert\Regex(
        pattern: SlugRule::BOUNDED_PATTERN,
        message: self::SLUG_MESSAGE,
        groups: [self::STEP_ADDRESS],
    )]
    public ?string $subCategoryNL = null;

    #[Assert\Regex(
        pattern: SlugRule::BOUNDED_PATTERN,
        message: self::SLUG_MESSAGE,
        groups: [self::STEP_ADDRESS],
    )]
    public ?string $subCategoryEN = null;

    #[Assert\Regex(
        pattern: SlugRule::BOUNDED_PATTERN,
        message: self::SLUG_MESSAGE,
        groups: [self::STEP_ADDRESS],
    )]
    public ?string $nameNL = null;

    #[Assert\Regex(
        pattern: SlugRule::BOUNDED_PATTERN,
        message: self::SLUG_MESSAGE,
        groups: [self::STEP_ADDRESS],
    )]
    public ?string $nameEN = null;

    public ?string $titleNL = null;

    public ?string $titleEN = null;

    public ?string $contentNL = null;

    public ?string $contentEN = null;

    public static function fromEntity(Page $page): self
    {
        $data = new self();
        $data->pageId = $page->getId();
        $data->requiredRole = $page->getRequiredRole();
        $data->categoryNL = $page->getCategory()->getValueNL();
        $data->categoryEN = $page->getCategory()->getValueEN();
        $data->subCategoryNL = $page->getSubCategory()->getValueNL();
        $data->subCategoryEN = $page->getSubCategory()->getValueEN();
        $data->nameNL = $page->getName()->getValueNL();
        $data->nameEN = $page->getName()->getValueEN();
        $data->titleNL = $page->getTitle()->getValueNL();
        $data->titleEN = $page->getTitle()->getValueEN();
        $data->contentNL = $page->getContent()->getValueNL();
        $data->contentEN = $page->getContent()->getValueEN();

        return $data;
    }

    public function applyTo(Page $page): void
    {
        $page->setRequiredRole($this->requiredRole);
        $page->getCategory()->updateValues(
            $this->categoryEN,
            $this->categoryNL,
        );
        $page->getSubCategory()->updateValues(
            $this->subCategoryEN,
            $this->subCategoryNL,
        );
        $page->getName()->updateValues(
            $this->nameEN,
            $this->nameNL,
        );
        $page->getTitle()->updateValues(
            $this->titleEN,
            $this->titleNL,
        );
        $page->getContent()->updateValues(
            $this->contentEN,
            $this->contentNL,
        );
    }

    /**
     * One part of the address as written in this language, or null when it was left empty.
     */
    public function slug(
        Languages $language,
        string $part,
    ): ?string {
        $value = trim(
            (string) match ($part . $language->name) {
                'category' . Languages::Dutch->name => $this->categoryNL,
            'category' . Languages::English->name => $this->categoryEN,
            'subCategory' . Languages::Dutch->name => $this->subCategoryNL,
            'subCategory' . Languages::English->name => $this->subCategoryEN,
            'name' . Languages::Dutch->name => $this->nameNL,
            default => $this->nameEN,
            },
        );

        return '' === $value
            ? null
            : $value;
    }
}
