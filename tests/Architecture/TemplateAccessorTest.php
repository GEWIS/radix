<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Node\Node;
use Twig\Source;

use function array_keys;
use function assert;
use function class_exists;
use function file_get_contents;
use function in_array;
use function is_string;
use function method_exists;
use function preg_match;
use function preg_match_all;
use function property_exists;
use function sort;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * A template names an accessor as a string, and nothing verifies that the method still exists. PHPStan does not
 * analyse templates, `lint:twig` only checks that they parse, and a name that resolves to nothing fails when the
 * template is rendered, which on an uncovered branch of a page means failing for the user.
 *
 * So every accessor a template names must still be declared somewhere. A component template reads its own component
 * as `this`, and the template path determines which class that is, so those names are checked against that class
 * alone. Every other name is checked against all of `src`. That does not prove the correct class declares it, which
 * would require types Twig does not record, but it does detect a name that is declared nowhere.
 *
 * Twig's own parser is used rather than a search of the text, because `bootstrap.Toast.getOrCreateInstance(el)` in a
 * `<script>` block is not a template expression and must not be matched as one.
 */
final class TemplateAccessorTest extends KernelTestCase
{
    /**
     * Accessors on objects the framework passes to a template, which this application does not declare.
     */
    private const array FROM_VENDOR = [
        'isFailureTransport',
    ];

    /**
     * Directory prefix of the component templates, which determines the class their `this` refers to.
     */
    private const string COMPONENTS = 'components/';

    /**
     * The extension every template carries, which the class name behind one does not.
     */
    private const string SUFFIX = '.html.twig';

    public function testEveryAccessorATemplateNamesStillExists(): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(
            Environment::class,
            $twig,
        );

        $known = $this->namesDeclaredInSource();
        $missing = [];
        $read = 0;

        foreach ($this->templates() as $file) {
            $code = file_get_contents($file->getPathname());
            if (false === $code) {
                continue;
            }

            $name = str_replace(
                __DIR__ . '/../../templates/',
                '',
                $file->getPathname(),
            );
            $tree = $twig->parse($twig->tokenize(new Source($code, $name)));
            $component = $this->componentBehind($name);

            foreach ($this->accessorsIn($tree) as [$readOff, $accessor]) {
                ++$read;

                // A component template reads its own component as `this`, and the template path determines which
                // class that is. Checking against that class alone prevents an unrelated class in `src` that
                // declares the same name from satisfying the check.
                if (
                    'this' === $readOff
                    && null !== $component
                ) {
                    if (
                        method_exists(
                            $component,
                            $accessor,
                        )
                        || property_exists(
                            $component,
                            $accessor,
                        )
                    ) {
                        continue;
                    }

                    $missing[$name . ': this.' . $accessor] = true;

                    continue;
                }

                if (
                    isset($known[$accessor])
                    || in_array(
                        $accessor,
                        self::FROM_VENDOR,
                        true,
                    )
                ) {
                    continue;
                }

                $missing[$name . ': ' . $accessor] = true;
            }
        }

        self::assertGreaterThan(
            100,
            $read,
            'Too few accessor reads were found for this to be meaningful.',
        );

        $missing = array_keys($missing);
        sort($missing);

        self::assertSame(
            [],
            $missing,
            'A template names an accessor that is no longer declared. Read the property instead, or restore it.',
        );
    }

    /**
     * Every attribute a template reads that is named as an accessor, with the variable it is read from, or null
     * when it is read from the result of another attribute access.
     *
     * @return iterable<array{string|null, string}>
     */
    private function accessorsIn(Node $node): iterable
    {
        // Twig stores the attribute of `x.foo` as a child node containing a constant, not as an attribute of it.
        if ($node->hasNode('attribute')) {
            $attribute = $node->getNode('attribute');
            $value = $attribute->hasAttribute('value')
                ? $attribute->getAttribute('value')
                : null;
            if (
                is_string($value)
                && 1 === preg_match(
                    '/^(get|is|has)[A-Z]/',
                    $value,
                )
            ) {
                $object = $node->getNode('node');
                $readOff = !$object->hasNode('attribute')
                    && $object->hasAttribute('name')
                    ? $object->getAttribute('name')
                    : null;

                yield [
                    is_string($readOff) ? $readOff : null,
                    $value,
                ];
            }
        }

        foreach ($node as $child) {
            yield from $this->accessorsIn($child);
        }
    }

    /**
     * The component class a template under `components/` belongs to, which is what its `this` refers to.
     *
     * Returns null for every other template, because nothing there records what an attribute is read from.
     *
     * @return class-string|null
     */
    private function componentBehind(string $template): ?string
    {
        if (
            !str_starts_with(
                $template,
                self::COMPONENTS,
            )
        ) {
            return null;
        }

        $relative = substr(
            $template,
            strlen(self::COMPONENTS),
        );

        // Stripped as a suffix rather than replaced wherever it occurs, so that a path repeating it elsewhere maps
        // to the class it names instead of to a mangled one that exists nowhere, which would quietly downgrade the
        // template to the loose whole-of-src check.
        if (
            !str_ends_with(
                $relative,
                self::SUFFIX,
            )
        ) {
            return null;
        }

        $class = 'App\\Twig\\Components\\' . str_replace(
            '/',
            '\\',
            substr(
                $relative,
                0,
                -strlen(self::SUFFIX),
            ),
        );

        return class_exists($class)
            ? $class
            : null;
    }

    /**
     * Every public method and property name declared under `src/`, which is what a template can read.
     *
     * Keyed by name rather than listed: this is asked about once per accessor a template reads, and a list of four
     * thousand names would be scanned from the start every time.
     *
     * @return array<string, true>
     */
    private function namesDeclaredInSource(): array
    {
        $names = [];

        foreach ($this->sources() as $file) {
            // Twig reads `x.foo` off an array as readily as off an object, so a key the code builds is a name a
            // template may read. Written-out keys are collected for that reason, from every file rather than only
            // from those that declare a class, because an array is built wherever it is convenient.
            //
            // Matched in key position only. Any accessor-shaped string literal would otherwise be collected, which
            // is how the operation ids in the OpenAPI factory added `getHealth` and four other names to this set.
            $code = file_get_contents($file->getPathname());
            if (false !== $code) {
                preg_match_all(
                    "/'((?:get|is|has)[A-Z][A-Za-z0-9_]*)'\s*=>/",
                    $code,
                    $keys,
                );
                foreach ($keys[1] as $key) {
                    $names[$key] = true;
                }
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

            $reflection = new ReflectionClass($class);

            // Only what the class declares itself. Inherited methods would include the entire framework: every
            // exception under `src/` inherits `getCode()` from `Exception`, which on its own would satisfy a
            // template that still reads `getCode` from a course.
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                $names[$method->getName()] = true;
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                $names[$property->getName()] = true;
            }
        }

        return $names;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function sources(): iterable
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src')) as $file) {
            assert($file instanceof SplFileInfo);
            if (
                $file->isDir()
                || 'php' !== $file->getExtension()
            ) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function templates(): iterable
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../templates')) as $file) {
            assert($file instanceof SplFileInfo);
            if (
                $file->isDir()
                || 'twig' !== $file->getExtension()
            ) {
                continue;
            }

            yield $file;
        }
    }
}
