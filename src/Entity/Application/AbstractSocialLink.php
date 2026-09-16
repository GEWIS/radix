<?php

declare(strict_types=1);

namespace App\Entity\Application;

use App\Entity\Application\Enums\SocialPlatform;
use App\Entity\Application\Traits\IdentifiableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use NoDiscard;

/**
 * One place a body or a company can be followed, as the platform and the handle it is known by there. The
 * address is never stored: {@see SocialPlatform::urlFor()} builds it when a page needs one, so no tracking
 * parameters from a pasted link are kept.
 *
 * A social link belongs to a revision rather than to the aggregate, so adding or dropping one goes through review like
 * everything else on the page. Each domain's concrete subclass is the entity, and declares the association back to the
 * revision that owns it; this class declares only what all of them have in common, as {@see LocalisedText} does.
 */
abstract class AbstractSocialLink
{
    use IdentifiableTrait;

    #[Column(type: Types::STRING)]
    public protected(set) string $handle = '';

    /**
     * Final so every concrete subclass shares this exact signature, which lets {@see self::copy()} use `new static()`.
     * The platform is set when the link is created and never changes: a handle only means anything on the platform it
     * was written for, and the handle is normalised against that platform when it is assigned.
     */
    final public function __construct(
        #[Column(
            type: Types::STRING,
            enumType: SocialPlatform::class,
        )]
        protected SocialPlatform $platform,
    ) {
    }

    public function getPlatform(): SocialPlatform
    {
        return $this->platform;
    }

    /**
     * Whatever arrives is reduced to a handle first, because people paste a profile link rather than type a username.
     * Doing it here rather than in a form means a fixture and an import cannot store a URL either.
     */
    public function setHandle(string $handle): void
    {
        $this->handle = $this->platform->normaliseHandle($handle);
    }

    /**
     * Where following this link leads.
     */
    public function getUrl(): string
    {
        return $this->platform->urlFor($this->handle);
    }

    /**
     * How the handle reads next to its icon.
     */
    public function getDisplayHandle(): string
    {
        return $this->platform->displayHandle($this->handle);
    }

    /**
     * A fresh, unpersisted copy for the cloners, so orphan removal can never delete the source revision's row.
     */
    #[NoDiscard]
    public function copy(): static
    {
        $copy = new static($this->platform);
        $copy->setHandle($this->handle);

        return $copy;
    }
}
