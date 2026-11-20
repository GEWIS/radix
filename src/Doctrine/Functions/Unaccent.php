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
 * UnaccentFunction ::= "UNACCENT" "(" StringPrimary ")"
 *
 * Folds the accents off an expression: the unaccent() extension on PostgreSQL, a pass-through on MariaDB, where
 * the ai_ci collation already ignores them. Doctrine salts the DQL cache key with the platform, so the two
 * translations never mix.
 */
class Unaccent extends FunctionNode
{
    public Node|string $input;

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->input = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $expression = $sqlWalker->walkStringPrimary($this->input);

        if ($sqlWalker->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return $expression;
        }

        return 'unaccent(' . $expression . ')';
    }
}
