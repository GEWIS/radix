<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Application\RevisableInterface;
use App\Entity\Application\RevisionInterface;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Repository\User\CompanyUserRepository;
use App\Service\Application\StaleRevisionDeletionBlock;
use App\Service\Application\StaleRevisionPolicyInterface;
use DateTime;
use Override;

use function array_filter;
use function array_values;

/**
 * When a company profile has been walked away from. Nothing about a profile is dated, so silence is all there is to
 * go on: a company and the first draft of it only exist together, and one nobody has come back to in a month is a
 * company that was entered and then thought better of.
 *
 * Which is why the guards below are worth having. Both a company's accounts and its administrative timeline are wiped
 * by the database along with the company row, so anything that says the arrangement became real — a package it was
 * sold, an account somebody signs in with — has to keep it standing.
 */
final readonly class CompanyStaleRevisionPolicy implements StaleRevisionPolicyInterface
{
    public function __construct(private CompanyUserRepository $companyUserRepository)
    {
    }

    #[Override]
    public function revisionClass(): string
    {
        return CompanyRevision::class;
    }

    #[Override]
    public function keepUntil(RevisionInterface $revision): ?DateTime
    {
        return null;
    }

    #[Override]
    public function deletionBlockedBy(RevisableInterface $revisable): ?StaleRevisionDeletionBlock
    {
        if (!$revisable instanceof Company) {
            return null;
        }

        if (!$revisable->getPackages()->isEmpty()) {
            return StaleRevisionDeletionBlock::hard('it has already been sold a package');
        }

        if ($this->companyUserRepository->count(['company' => $revisable]) > 0) {
            return StaleRevisionDeletionBlock::hard('a representative still has an account for it');
        }

        return null;
    }

    #[Override]
    public function storedPaths(RevisionInterface $revision): array
    {
        if (!$revision instanceof CompanyRevision) {
            return [];
        }

        return array_values(array_filter(
            [
                $revision->getSquareLogo(),
                $revision->getBannerLogo(),
            ],
            static fn (?string $path): bool => null !== $path,
        ));
    }
}
