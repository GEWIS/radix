<?php

declare(strict_types=1);

namespace App\Tests\Doctrine\Query;

use App\Doctrine\Query\Queryable;
use App\Doctrine\Query\QueryableWalker;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\QueryException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The query console runs DQL an administrator types by hand, so what a query may reach is an allowlist and a write is
 * refused. The walker runs inside the parser, before any SQL is generated.
 */
final class QueryableWalkerTest extends KernelTestCase
{
    public function testAnEntityThatDeclaresTheAttributeIsQueryable(): void
    {
        self::assertTrue(Queryable::isDeclaredOn(Member::class));
    }

    public function testAnEntityThatDoesNotDeclareTheAttributeIsNotQueryable(): void
    {
        self::assertFalse(Queryable::isDeclaredOn(User::class));
    }

    public function testAQueryOnAnAllowlistedEntityRuns(): void
    {
        $query = $this->query('SELECT m.lidnr FROM ' . Member::class . ' m');

        self::assertIsArray($query->getResult());
    }

    public function testAQueryOnAnEntityOutsideTheAllowlistIsRefused(): void
    {
        $query = $this->query('SELECT u.lidnr FROM ' . User::class . ' u');

        $this->expectException(QueryException::class);

        $query->getResult();
    }

    public function testAnUpdateIsRefused(): void
    {
        $query = $this->query('UPDATE ' . Member::class . ' m SET m.lidnr = 1');

        $this->expectException(QueryException::class);

        $query->execute();
    }

    public function testADeleteIsRefused(): void
    {
        $query = $this->query('DELETE ' . Member::class . ' m');

        $this->expectException(QueryException::class);

        $query->execute();
    }

    /**
     * @return Query<int, object>
     */
    private function query(string $dql): Query
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine')->getManager('application');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $entityManager,
        );

        $query = $entityManager->createQuery($dql);
        $query->setHint(
            Query::HINT_CUSTOM_TREE_WALKERS,
            [QueryableWalker::class],
        );

        return $query;
    }
}
