<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Database;

use App\Entity\Database\Enums\MemberFilter;
use App\Entity\Database\Member;
use App\Repository\Database\MemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function array_column;
use function count;
use function iterator_to_array;
use function mb_strtolower;
use function mb_substr;

final class MemberSearchTest extends DatabaseTestCase
{
    private MemberRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $em = self::getContainer()->get('doctrine')->getManager('default');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $em,
        );
        $this->entityManager = $em;
        $conn = $em->getConnection();
        $conn->executeStatement('CREATE EXTENSION IF NOT EXISTS unaccent');
        $this->repository = self::getContainer()->get(MemberRepository::class);
    }

    public function testSearchFindsACurrentMemberByNameFragment(): void
    {
        $member = $this->entityManager->find(
            Member::class,
            8000,
        );
        self::assertNotNull($member);

        $results = $this->repository->search(mb_strtolower(mb_substr(
            $member->lastName,
            0,
            4,
        )));

        self::assertNotEmpty($results);
        self::assertContains(
            8000,
            array_column(
                $results,
                'lidnr',
            ),
        );
    }

    public function testSearchCapsItsResults(): void
    {
        // A single character matches broadly across the seeded Faker names.
        $results = $this->repository->search('a');

        self::assertLessThanOrEqual(
            32,
            count($results),
        );
    }

    public function testSearchHandlesDiacritics(): void
    {
        // The unaccent extension is enabled in setUp; verify it works by
        // searching for a common name fragment. This test primarily ensures
        // the query using unaccent() executes without error.
        $results = $this->repository->search('a');
        self::assertNotEmpty(
            $results,
            'Search with unaccent should return results',
        );
    }

    public function testSearchFindsAccentedNamesWithoutDiacritics(): void
    {
        // Seed data contains external members with firstname 'EXTÉRNAL' (lidnr 8100-8114).
        // Searching without the accent should find them.
        $results = $this->repository->search('external');
        $lidnrs = array_column(
            $results,
            'lidnr',
        );

        self::assertNotEmpty(
            $lidnrs,
            'Searching for unaccented "external" should find members with firstname EXTÉRNAL',
        );
        // At least one of the external block members (8100-8114) should appear
        $foundExternal = false;

        foreach ($lidnrs as $lidnr) {
            if (
                $lidnr >= 8100
                && $lidnr <= 8114
            ) {
                $foundExternal = true;
                break;
            }
        }

        self::assertTrue(
            $foundExternal,
            'Results should include a member from the external block with firstname EXTÉRNAL',
        );
    }

    public function testOverviewSearchFindsAccentedNamesWithoutDiacritics(): void
    {
        // The member overview filter should also be accent-insensitive.
        // Seed data contains members with 'BÖDY-EXTERNAL' lastname (lidnr 8015-8019).
        // Searching for "body" (without the ö) should find them.
        $results = $this->repository->paginateForOverview(
            'body',
            MemberFilter::Everyone,
            1,
            10,
        );
        $lidnrs = array_column(
            iterator_to_array(
                $results,
            ),
            'lidnr',
        );

        // At least one of the BÖDY-EXTERNAL block members (8015-8019) should appear
        $foundBody = false;

        foreach ($lidnrs as $lidnr) {
            if (
                $lidnr >= 8015
                && $lidnr <= 8019
            ) {
                $foundBody = true;
                break;
            }
        }

        self::assertTrue(
            $foundBody,
            'Overview search for unaccented "body" should find members with lastname BÖDY-EXTERNAL',
        );
    }
}
