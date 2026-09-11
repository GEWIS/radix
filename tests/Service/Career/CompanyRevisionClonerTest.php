<?php

declare(strict_types=1);

namespace App\Tests\Service\Career;

use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\CareerLocalisedText;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Entity\Decision\Member;
use App\Service\Career\CompanyRevisionCloner;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Spawning the next company-profile draft must produce an independent copy: the localised texts (slogan, description,
 * website) become fresh rows (the OneToOne relations are orphan-removing, so a shared row would be deleted with the
 * source), while the logo and contact details are plain scalars copied by value. These tests pin that contract so a
 * regression cannot blank an editor's draft or delete live content.
 */
final class CompanyRevisionClonerTest extends TestCase
{
    private CompanyRevisionCloner $cloner;

    #[Override]
    protected function setUp(): void
    {
        $this->cloner = new CompanyRevisionCloner();
    }

    public function testStartsADraftLinkedIntoTheChainCarryingTheAuthor(): void
    {
        $author = self::createStub(Member::class);
        $source = $this->approvedSource($author);
        $source->setRevisionNumber(2);
        $company = $source->company;

        $draft = $this->cloner->cloneAsDraft($source);
        self::assertInstanceOf(
            CompanyRevision::class,
            $draft,
        );

        self::assertSame(
            $source,
            $draft->getPreviousRevision(),
        );
        self::assertSame(
            $draft,
            $company->getCurrentRevision(),
        );
        self::assertTrue($company->getRevisions()->contains($draft));
        self::assertSame(
            RevisionStatus::Draft,
            $draft->getStatus(),
        );
        self::assertSame(
            3,
            $draft->getRevisionNumber(),
        );
        self::assertSame(
            $author,
            $draft->getAuthor(),
        );
        self::assertNull($draft->getAuthorCompanyUser());
    }

    public function testDeepCopiesTheLocalisedTextsAndCopiesTheScalarsByValue(): void
    {
        $source = $this->approvedSource();

        $draft = $this->cloner->cloneAsDraft($source);
        self::assertInstanceOf(
            CompanyRevision::class,
            $draft,
        );

        $this->assertCopiedNotShared(
            $source->slogan,
            $draft->slogan,
        );
        $this->assertCopiedNotShared(
            $source->description,
            $draft->description,
        );
        $this->assertCopiedNotShared(
            $source->website,
            $draft->website,
        );

        self::assertSame(
            'square.png',
            $draft->squareLogo,
        );
        self::assertSame(
            'banner.png',
            $draft->bannerLogo,
        );
        self::assertSame(
            'Jane Doe',
            $draft->contactName,
        );
        self::assertSame(
            'Street 1',
            $draft->contactAddress,
        );
        self::assertSame(
            'jane@example.com',
            $draft->contactEmail,
        );
        self::assertSame(
            '+31 600000000',
            $draft->contactPhone,
        );
    }

    private function assertCopiedNotShared(
        CareerLocalisedText $source,
        CareerLocalisedText $draft,
    ): void {
        self::assertNotSame(
            $source,
            $draft,
            'localised text must be a fresh row, not the shared (orphan-removing) source row',
        );
        self::assertSame(
            $source->getValueNL(),
            $draft->getValueNL(),
        );
        self::assertSame(
            $source->getValueEN(),
            $draft->getValueEN(),
        );
    }

    private function approvedSource(?Member $author = null): CompanyRevision
    {
        $company = new Company();

        $source = new CompanyRevision();
        $company->addRevision($source);
        $company->setCurrentRevision($source);

        $source->setStatus(RevisionStatus::Approved);
        $source->setRevisionNumber(1);
        $source->setAuthor($author ?? self::createStub(Member::class));
        $source->slogan = $this->text(
            'We build.',
            'Wij bouwen.',
        );
        $source->description = $this->text(
            'A description.',
            'Een beschrijving.',
        );
        $source->website = $this->text(
            'https://example.com/en',
            'https://example.com/nl',
        );
        $source->squareLogo = 'square.png';
        $source->bannerLogo = 'banner.png';
        $source->contactName = 'Jane Doe';
        $source->contactAddress = 'Street 1';
        $source->contactEmail = 'jane@example.com';
        $source->contactPhone = '+31 600000000';

        return $source;
    }

    private function text(
        string $en,
        string $nl,
    ): CareerLocalisedText {
        return new CareerLocalisedText(
            $en,
            $nl,
        );
    }
}
