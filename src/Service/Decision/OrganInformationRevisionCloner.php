<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Application\AbstractRevision;
use App\Entity\Application\RevisionInterface;
use App\Entity\Decision\OrganInformationRevision;
use App\Workflow\AbstractRevisionCloner;
use Override;

use function assert;

/**
 * Spawns the next draft {@see OrganInformationRevision} from an existing one, whether because the board requested
 * changes, because a rejected page is being reopened, or because a body wants to change what is already on the website.
 * The localised texts and the social links are deep-copied into fresh rows so orphan removal can never delete the
 * source revision's content; the images and the crops on them are copied by value.
 */
final readonly class OrganInformationRevisionCloner extends AbstractRevisionCloner
{
    #[Override]
    public function supports(RevisionInterface $revision): bool
    {
        return $revision instanceof OrganInformationRevision;
    }

    #[Override]
    protected function spawnDraft(RevisionInterface $source): OrganInformationRevision
    {
        assert($source instanceof OrganInformationRevision);

        $information = $source->organInformation;

        $draft = new OrganInformationRevision();
        $draft->setPreviousRevision($source);
        $information->addRevision($draft);
        $information->setCurrentRevision($draft);

        return $draft;
    }

    #[Override]
    protected function copyContent(
        RevisionInterface $source,
        AbstractRevision $draft,
    ): void {
        assert($source instanceof OrganInformationRevision);
        assert($draft instanceof OrganInformationRevision);

        $draft->shortDescription = $source->shortDescription->copy();
        $draft->description = $source->description->copy();
        $draft->email = $source->email;
        $draft->website = $source->website;
        $draft->bannerSource = $source->bannerSource;
        $draft->bannerCrop = $source->bannerCrop;
        $draft->bannerPath = $source->bannerPath;
        $draft->logoSource = $source->logoSource;
        $draft->logoCrop = $source->logoCrop;
        $draft->logoPath = $source->logoPath;

        foreach ($source->getSocialLinks() as $link) {
            $copy = $link->copy();
            $copy->revision = $draft;
            $draft->getSocialLinks()->add($copy);
        }
    }
}
