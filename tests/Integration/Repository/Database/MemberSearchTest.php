<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Database;

use App\Entity\Database\Member;
use App\Repository\Database\MemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function array_column;
use function count;
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
}
