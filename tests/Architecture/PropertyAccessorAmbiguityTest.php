<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionProperty;
use SplFileInfo;
use Twig\Environment;

use function assert;
use function class_exists;
use function realpath;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function substr;
use function ucfirst;

/**
 * Twig resolves `x.foo` to a public property `foo` first and only then to `getFoo()`, so a class that declares both
 * makes the two spellings of one read mean two different things, and which one a template gets depends on a detail the
 * template author cannot see.
 *
 * A predicate counts too, and that is not a nicety. PropertyAccessor resolves a path by trying `getFoo()`, then
 * `isFoo()`, `hasFoo()` and `canFoo()`, then a method named `foo()`, and only then the property, so a `hasFoo()` beside
 * a public `$foo` is what a form reads for `foo`: a boolean where the thing itself was meant. That is how the
 * mailing-list form ended up passing Symfony `false` where it wanted an entity.
 *
 * Enums are exempt because every one of them has `name` and `value` of its own, which is exactly why a template reads
 * `getName()` in full from an enum rather than `.name`.
 *
 * @see Environment::getAttribute() for the resolution order this rests on
 */
final class PropertyAccessorAmbiguityTest extends TestCase
{
    /**
     * The prefixes an accessor is looked for under, which are Symfony's own defaults in
     * {@see \Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor::$accessorPrefixes}. A method named exactly
     * like the property is tried as well, and is checked for beside these.
     */
    private const array PREFIXES = [
        'get',
        'is',
        'has',
        'can',
    ];

    /**
     * The whole of the application. An entity is where this matters most, but a form binds to plain data classes and
     * a template reads a component, and the resolution is the same wherever it happens.
     */
    private const array ROOTS = [
        __DIR__ . '/../../src',
    ];

    public function testNoPublicPropertyIsShadowedByAGetter(): void
    {
        $ambiguous = [];

        foreach (self::ROOTS as $root) {
            foreach ($this->classesIn($root) as $class) {
                if ($class->isEnum()) {
                    continue;
                }

                foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                    foreach ($this->accessorsFor($property->getName()) as $accessor) {
                        if (!$class->hasMethod($accessor)) {
                            continue;
                        }

                        $method = $class->getMethod($accessor);

                        // A static method is not an accessor: PropertyAccessor reads an instance, and neither it nor
                        // the extractor behind it considers one. That is what lets the named constructor
                        // {@see \App\Service\Application\StaleRevisionDeletionBlock::forceable()} stand beside the
                        // property of the same name.
                        if (
                            !$method->isPublic()
                            || $method->isStatic()
                        ) {
                            continue;
                        }

                        // A pair a vendor trait brought with it (`$formName` beside `getFormName()` is Symfony UX's
                        // own) is not ours to name differently.
                        if (
                            !$this->isOurs($method->getFileName())
                            || !$this->isOurs($property->getDeclaringClass()->getFileName())
                        ) {
                            continue;
                        }

                        $ambiguous[] = sprintf(
                            '%s has both $%s and %s()',
                            $class->getName(),
                            $property->getName(),
                            $accessor,
                        );
                    }
                }
            }
        }

        self::assertSame(
            [],
            $ambiguous,
            'A template reading `.foo` would get the property and never the getter. Remove one of the two.',
        );
    }

    /**
     * Every method name that resolves to the given property before the property itself does.
     *
     * @return list<string>
     */
    private function accessorsFor(string $property): array
    {
        $accessors = [$property];

        foreach (self::PREFIXES as $prefix) {
            $accessors[] = $prefix . ucfirst($property);
        }

        return $accessors;
    }

    private function isOurs(string|false $file): bool
    {
        return false !== $file
            && str_starts_with(
                $file,
                realpath(__DIR__ . '/../../src') . '/',
            );
    }

    /**
     * @return iterable<ReflectionClass<object>>
     */
    private function classesIn(string $root): iterable
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            assert($file instanceof SplFileInfo);
            if (
                $file->isDir()
                || 'php' !== $file->getExtension()
            ) {
                continue;
            }

            $relative = str_replace(
                __DIR__ . '/../../src/',
                '',
                $file->getPathname(),
            );
            $class = 'App\\' . str_replace(
                '/',
                '\\',
                substr(
                    $relative,
                    0,
                    -4,
                ),
            );
            if (!class_exists($class)) {
                continue;
            }

            yield new ReflectionClass($class);
        }
    }
}
