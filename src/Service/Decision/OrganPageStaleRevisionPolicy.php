<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Application\RevisableInterface;
use App\Entity\Application\RevisionInterface;
use App\Entity\Decision\OrganInformationRevision;
use App\Service\Application\StaleRevisionPolicyInterface;
use DateTime;
use Override;

use function array_filter;
use function array_values;

/**
 * When a body's page has been walked away from. A page says nothing about when it stops being true and carries
 * nothing that would be lost with it, so a first draft nobody came back to simply goes; the body starts a new page
 * whenever it wants one, and the organ itself is the decisions' business rather than this page's.
 *
 * All four image columns are reported: an upload and the cut made from it are separate files, and a cloned revision
 * shares both with the one it came from, so all of them have to be offered back to storage for the reference check to
 * be the thing that decides.
 */
final readonly class OrganPageStaleRevisionPolicy implements StaleRevisionPolicyInterface
{
    #[Override]
    public function revisionClass(): string
    {
        return OrganInformationRevision::class;
    }

    #[Override]
    public function keepUntil(RevisionInterface $revision): ?DateTime
    {
        return null;
    }

    #[Override]
    public function deletionBlockedBy(RevisableInterface $revisable): ?string
    {
        return null;
    }

    #[Override]
    public function storedPaths(RevisionInterface $revision): array
    {
        if (!$revision instanceof OrganInformationRevision) {
            return [];
        }

        return array_values(array_filter(
            [
                $revision->getBannerSource(),
                $revision->getBannerPath(),
                $revision->getLogoSource(),
                $revision->getLogoPath(),
            ],
            static fn (?string $path): bool => null !== $path,
        ));
    }
}
