<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Entity\Database\Decision;
use App\Form\Activity\PeriodProposalLimitType;
use App\Form\Activity\ProposalLimitType;
use App\Form\Application\Flow\AbstractStepperFlowType;
use Doctrine\Common\Collections\Collection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface as OptionsResolverException;
use Symfony\Component\PropertyAccess\PropertyAccess;

use function assert;
use function class_exists;
use function in_array;
use function is_a;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function substr;

/**
 * A form writes submitted data back through Symfony's PropertyAccessor, which prefers a public setter and otherwise
 * writes the property directly. It cannot write a `private(set)` property and has no reflection fallback, so a field
 * bound to read-only state fails on submission rather than when the form is built, and nothing detects it before
 * that point.
 *
 * That is the constraint behind the rule the entities follow: state a form writes is a plain public property, and
 * `public private(set)` is for state written through a method of its own.
 *
 * The forms are constructed rather than parsed, because whether a field is mapped and what it maps to are resolved
 * at runtime: `mapped` can arrive through an `array_merge` or from a static method, and reading the source gives the
 * wrong answer. PropertyAccessor is called rather than reimplemented for the same reason, because it camelises a
 * name, writes a collection through an adder and remover, and calls magic methods.
 */
final class FormWritabilityTest extends KernelTestCase
{
    /**
     * Types that read `data` from their options while being constructed, which only their caller can supply.
     */
    private const array NEEDS_DATA = [
        PeriodProposalLimitType::class,
        ProposalLimitType::class,
    ];

    /**
     * Entities whose forms declare fields for a different object and map those fields manually on submission.
     *
     * The register's decision forms all use `data_class => Decision::class` and construct the recorded sub-decision
     * from fields of their own, so a mapped field of theirs that is absent from `Decision` is expected. Elsewhere a
     * mapped field must name the property it writes.
     */
    private const array ARRANGES_ITS_OWN_FIELDS = [Decision::class];

    public function testEveryMappedFieldCanBeWritten(): void
    {
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(
            FormFactoryInterface::class,
            $factory,
        );

        $accessor = PropertyAccess::createPropertyAccessorBuilder()
            ->enableMagicCall()
            ->getPropertyAccessor();

        $unwritable = [];
        $unnamed = [];
        $built = 0;
        $skipped = 0;

        foreach ($this->formTypes() as $type) {
            // Neither can be constructed here. A flow determines its current step from the data it is created
            // with, and that data is stored on the session of a request.
            if (
                in_array(
                    $type,
                    self::NEEDS_DATA,
                    true,
                )
                || is_a(
                    $type,
                    AbstractStepperFlowType::class,
                    true,
                )
            ) {
                ++$skipped;

                continue;
            }

            try {
                $form = $factory->create($type);
            } catch (OptionsResolverException) {
                // The type requires options that only its caller can supply, so there is no form to inspect. Only
                // the resolver's exception is caught: a type that fails to build for any other reason, such as
                // calling a removed accessor while adding its fields, is what this test exists to detect.
                ++$skipped;

                continue;
            }

            ++$built;

            /** @var class-string|null $data */
            $data = $form->getConfig()->getDataClass();
            if (
                null === $data
                || !str_starts_with(
                    $data,
                    'App\\Entity\\',
                )
            ) {
                continue;
            }

            $bound = new ReflectionClass($data);

            // An abstract data_class is populated with a concrete subclass at runtime, and it is that class the
            // write has to reach. There is no instance to check here.
            if ($bound->isAbstract()) {
                continue;
            }

            $entity = $bound->newInstanceWithoutConstructor();

            $paths = $this->mappedPathsIn(
                $form,
                $data,
            );

            foreach ($paths as $path) {
                if (
                    $accessor->isWritable(
                        $entity,
                        $path,
                    )
                ) {
                    continue;
                }

                // A mapped field that is absent from the entity is reported, unless the form declares its own
                // fields. Renaming a property leaves the field bound to a name that no longer exists, and absent
                // combined with unwritable is the one case a writability check does not detect on its own.
                if (!$bound->hasProperty($path)) {
                    if (
                        !in_array(
                            $data,
                            self::ARRANGES_ITS_OWN_FIELDS,
                            true,
                        )
                    ) {
                        $unnamed[] = sprintf(
                            '%s binds %s::$%s, which the entity does not declare',
                            new ReflectionClass($type)->getShortName(),
                            $bound->getShortName(),
                            $path,
                        );
                    }

                    continue;
                }

                $property = $bound->getProperty($path);

                // A collection is never written: Symfony's collection listeners modify the existing collection in
                // place through adders and removers, which requires only read access.
                $propertyType = $property->getType();
                if (
                    $propertyType instanceof ReflectionNamedType
                    && is_a(
                        $propertyType->getName(),
                        Collection::class,
                        true,
                    )
                ) {
                    continue;
                }

                // A promoted property belongs to a value object the form constructs through `empty_data` rather
                // than writes into. The entities this test guards declare their properties normally, so no error of
                // that kind is hidden here.
                if ($property->isPromoted()) {
                    continue;
                }

                $unwritable[] = sprintf(
                    '%s binds %s::$%s, which PropertyAccessor cannot write',
                    new ReflectionClass($type)->getShortName(),
                    $bound->getShortName(),
                    $path,
                );
            }
        }

        self::assertGreaterThan(
            40,
            $built,
            'Too few forms could be built for this to be meaningful.',
        );
        self::assertSame(
            [],
            $unwritable,
            sprintf(
                'Give the property a public setter, or make it a plain public property. '
                . '(%d form types built, %d not inspected because they require options or data.)',
                $built,
                $skipped,
            ),
        );
        self::assertSame(
            [],
            $unnamed,
            'A mapped field is absent from the entity it is bound to and cannot be written. Correct the field, or '
            . 'add the entity to ARRANGES_ITS_OWN_FIELDS if its forms declare fields for a different object.',
        );
    }

    /**
     * Every property path a form writes onto its own data class, including the paths of a field that a sub-form
     * contributes.
     *
     * A compound child that inherits its parent's data, or that declares the same data class, writes onto the same
     * object, so its own children are fields of that object and are read as if the parent declared them. Without
     * this only the top level is inspected, and a field bound to read-only state through an `inherit_data` sub-form
     * fails on submission, which is the sequence this test exists to prevent. A child binding a different class is
     * left to the form that has that class as its own `data_class`.
     *
     * @param FormInterface<mixed> $form
     * @param class-string         $data
     *
     * @return iterable<string>
     */
    private function mappedPathsIn(
        FormInterface $form,
        string $data,
    ): iterable {
        foreach ($form as $child) {
            $config = $child->getConfig();
            if (!$config->getMapped()) {
                continue;
            }

            if (
                $config->getCompound()
                && (
                    $config->getInheritData()
                    || $config->getDataClass() === $data
                )
            ) {
                yield from $this->mappedPathsIn(
                    $child,
                    $data,
                );

                continue;
            }

            yield (string) ($config->getPropertyPath() ?? $child->getName());
        }
    }

    /**
     * @return iterable<class-string<FormTypeInterface<mixed>>>
     */
    private function formTypes(): iterable
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src/Form')) as $file) {
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

            /** @var class-string $class */
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
            if (
                $reflection->isAbstract()
                || !$reflection->implementsInterface(FormTypeInterface::class)
            ) {
                continue;
            }

            /** @var class-string<FormTypeInterface<mixed>> $formType */
            $formType = $class;

            yield $formType;
        }
    }
}
