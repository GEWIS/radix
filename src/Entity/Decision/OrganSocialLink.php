<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Entity\Application\AbstractSocialLink;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * Somewhere a body can be followed, as recorded on one revision of its page.
 */
#[Entity]
class OrganSocialLink extends AbstractSocialLink
{
    #[ManyToOne(
        targetEntity: OrganInformationRevision::class,
        inversedBy: 'socialLinks',
    )]
    #[JoinColumn(nullable: false)]
    public OrganInformationRevision $revision;
}
