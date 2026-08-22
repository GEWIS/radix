<?php

declare(strict_types=1);

namespace App\Doctrine\Functions;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * YearFunction ::= "YEAR" "(" ArithmeticPrimary ")"
 */
class Year extends FunctionNode
{
    public Node|string $yearExpression;

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->yearExpression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    /**
     * The ledger runs on PostgreSQL and everything else on MariaDB, and only the latter knows `YEAR()`, so the platform
     * behind the query decides which spelling comes out. Doctrine salts the DQL cache key with the platform, so the two
     * translations of one query never stand in for each other.
     */
    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $expression = $sqlWalker->walkArithmeticPrimary($this->yearExpression);

        if ($sqlWalker->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return 'YEAR(' . $expression . ')';
        }

        return 'EXTRACT(YEAR FROM ' . $expression . ')';
    }
}
