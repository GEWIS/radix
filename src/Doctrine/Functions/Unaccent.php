<?php

declare(strict_types=1);

namespace App\Doctrine\Functions;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * UnaccentFunction ::= "UNACCENT" "(" StringPrimary ")"
 *
 * Folds the accents off an expression through the unaccent() extension.
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

        return 'unaccent(' . $expression . ')';
    }
}
