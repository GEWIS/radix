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
 * MonthFunction ::= "MONTH" "(" ArithmeticPrimary ")"
 *
 * MariaDB spells the extraction `MONTH()`, PostgreSQL `EXTRACT(MONTH FROM ...)`; the platform behind the query
 * decides. Doctrine salts the DQL cache key with the platform, so the two translations never mix.
 */
class Month extends FunctionNode
{
    public Node|string $monthExpression;

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->monthExpression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $expression = $sqlWalker->walkArithmeticPrimary($this->monthExpression);

        if ($sqlWalker->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return 'MONTH(' . $expression . ')';
        }

        return 'EXTRACT(MONTH FROM ' . $expression . ')';
    }
}
