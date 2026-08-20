<?php

declare(strict_types=1);

namespace App\Doctrine\Query;

use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\InstanceOfExpression;
use Doctrine\ORM\Query\AST\NewObjectExpression;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\TreeWalkerAdapter;
use Override;

use function class_exists;
use function get_object_vars;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Refuses a console query that addresses an entity which is not marked #[Queryable], and any query that writes.
 *
 * A custom tree walker runs inside Doctrine\ORM\Query\Parser::parse(), over the finished AST and before the output
 * walker is even constructed, so a query is turned down while it is still DQL: no SQL is generated for it and the
 * connection is never asked for anything.
 *
 * Two passes are needed, because an entity can be reached with or without an alias. The parser's alias map is complete
 * by this point — it holds a component for the FROM clause, for every join and for every subselect — which is what
 * lets the first pass cover the paths a mapping boundary never could: `SELECT m, t FROM db:Member m JOIN m.tags t`
 * leaves the projection through an association the projection itself declares, and the entity it arrives at,
 * App\Entity\Photo\MemberTag, is a component here like any other. But `SELECT SIZE(m.tags) FROM db:Member m` reads
 * that same table from a subquery Doctrine writes itself, and never names an alias for it; SIZE, IS EMPTY, MEMBER OF
 * and IDENTITY all do that, and NEW and INSTANCE OF name a class outright without one either. So the second pass
 * walks the AST and applies the same question to every association target and every class name it finds there.
 */
final class QueryableWalker extends TreeWalkerAdapter
{
    #[Override]
    public function walkSelectStatement(SelectStatement $selectStatement): void
    {
        foreach ($this->getQueryComponents() as $dqlAlias => $queryComponent) {
            // A component without metadata is a result variable, an alias the query gave to an expression it selects,
            // and addresses no entity of its own.
            if (!isset($queryComponent['metadata'])) {
                continue;
            }

            $this->assertQueryable(
                $queryComponent['metadata']->getName(),
                $dqlAlias,
            );
        }

        $this->assertNodeIsQueryable($selectStatement);
    }

    #[Override]
    public function walkUpdateStatement(UpdateStatement $updateStatement): void
    {
        throw self::readOnly();
    }

    #[Override]
    public function walkDeleteStatement(DeleteStatement $deleteStatement): void
    {
        throw self::readOnly();
    }

    /**
     * Descends through everything the AST holds, so that a node is judged wherever it sits — in the select clause, in
     * a WHERE, inside a function's arguments or several subselects down.
     */
    private function assertNodeIsQueryable(mixed $node): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->assertNodeIsQueryable($child);
            }

            return;
        }

        if (!$node instanceof Node) {
            return;
        }

        if ($node instanceof PathExpression) {
            $this->assertPathIsQueryable($node);
        } elseif ($node instanceof NewObjectExpression) {
            // The parser resolved this name against the FROM clause's namespace and checked that the class exists
            // before any walker ran, so it is the class the query really names rather than what was typed.
            $this->assertQueryable(
                $node->className,
                $node->className,
            );
        } elseif ($node instanceof InstanceOfExpression) {
            foreach ($node->value as $value) {
                // The other form is an input parameter, whose class is not known until the query is bound; Doctrine
                // itself refuses one from outside the hierarchy when it generates the SQL.
                if (
                    !is_string($value)
                    || !class_exists($value)
                ) {
                    continue;
                }

                $this->assertQueryable(
                    $value,
                    $value,
                );
            }
        }

        foreach (get_object_vars($node) as $child) {
            $this->assertNodeIsQueryable($child);
        }
    }

    /**
     * A path whose field is an association reaches the entity on the other side of it, whether or not the query gave
     * that entity an alias.
     */
    private function assertPathIsQueryable(PathExpression $pathExpression): void
    {
        $metadata = $this->getQueryComponents()[$pathExpression->identificationVariable]['metadata'] ?? null;

        if (
            null === $metadata
            || null === $pathExpression->field
            || !$metadata->hasAssociation($pathExpression->field)
        ) {
            return;
        }

        $this->assertQueryable(
            $metadata->associationMappings[$pathExpression->field]->targetEntity,
            $pathExpression->identificationVariable . '.' . $pathExpression->field,
        );
    }

    /**
     * @param class-string $class
     * @param string       $named the author's own word for what they reached for — an alias, a path or the class name
     *                            they typed — so that pointing at it says which part of the query to fix without the
     *                            answer itself confirming anything about what the database holds
     */
    private function assertQueryable(
        string $class,
        string $named,
    ): void {
        if (Queryable::isDeclaredOn($class)) {
            return;
        }

        throw QueryException::semanticalError(
            sprintf(
                'Only the entities listed beside the editor may be queried; "%s" is not one of them.',
                $named,
            ),
        );
    }

    /**
     * A DQL UPDATE or DELETE does not go through the unit of work: its executor hands the statement straight to the
     * connection, which is why one typed into the console would really run, on the same MariaDB the site's own tables
     * live in. The console is a read surface, and this is where that is stated.
     */
    private static function readOnly(): QueryException
    {
        return QueryException::semanticalError('The query console only runs SELECT statements.');
    }
}
