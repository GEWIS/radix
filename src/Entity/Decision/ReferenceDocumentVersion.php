<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * One uploaded file of a {@see ReferenceDocument}.
 */
#[Entity]
#[HasLifecycleCallbacks]
class ReferenceDocumentVersion extends AbstractDocumentVersion
{
    #[ManyToOne(
        targetEntity: ReferenceDocument::class,
        inversedBy: 'versions',
    )]
    #[JoinColumn(
        name: 'referenceDocument_id',
        nullable: false,
    )]
    public private(set) ReferenceDocument $referenceDocument;

    public function setReferenceDocument(ReferenceDocument $referenceDocument): void
    {
        $referenceDocument->addVersion($this);
        $this->referenceDocument = $referenceDocument;
    }
}
