<?php

declare(strict_types=1);

namespace App\Doctrine\Functions;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * This extension adds SQL RAND() functionality to DQL for returning random
 * database entries.
 * This code originates from https://gist.github.com/Ocramius/919465.
 *
 * Usage: Call addSelect('RAND() as HIDDEN rand') and orderBy('rand') on a QueryBuilder object
 *
 * MariaDB spells it `RAND()` and PostgreSQL `random()`; the platform behind the query decides. Doctrine salts the
 * DQL cache key with the platform, so the two translations never mix.
 */
class Rand extends FunctionNode
{
    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        if ($sqlWalker->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return 'RAND()';
        }

        return 'random()';
    }
}
