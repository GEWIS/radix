<?php

declare(strict_types=1);

namespace App\Doctrine\Query;

use Attribute;
use ReflectionClass;

use function count;

/**
 * Marks an entity as one the query console may address.
 *
 * The console runs DQL that an administrator types by hand, on the manager that maps the whole site, so what a query
 * may reach cannot be left to the query: it is an allowlist, and this attribute is how a class joins it. Annotating a
 * class changes nothing about how it is mapped or loaded; the only reader is App\Doctrine\Query\QueryableWalker,
 * which the console installs on every query it runs.
 *
 * The attribute is deliberately not inherited — PHP does not inherit attributes, and this class does not walk the
 * parent chain to make up for it. A class is queryable because it says so itself, which is why every concrete
 * subdecision carries the attribute next to its parent, and why an entity added under App\Entity\Decision later is
 * out of the console's reach until someone puts it there on purpose.
 */
#[Attribute(flags: Attribute::TARGET_CLASS)]
final class Queryable
{
    /**
     * Whether the class itself declares the attribute.
     *
     * @param class-string $class
     */
    public static function isDeclaredOn(string $class): bool
    {
        return 0 !== count(new ReflectionClass($class)->getAttributes(self::class));
    }
}
