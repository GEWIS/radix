<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Entity\Application\RevisionInterface;
use App\Workflow\RevisionClonerInterface;
use App\Workflow\RevisionClonerRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function get_debug_type;

/**
 * Callers stay domain-agnostic: the registry finds the cloner that supports the revision and delegates to it.
 */
final class RevisionClonerRegistryTest extends TestCase
{
    public function testTheClonerThatSupportsTheRevisionIsUsed(): void
    {
        $source = self::createStub(RevisionInterface::class);
        $draft = self::createStub(RevisionInterface::class);

        $unsupporting = self::createStub(RevisionClonerInterface::class);
        $unsupporting->method('supports')->willReturn(false);

        $supporting = self::createStub(RevisionClonerInterface::class);
        $supporting->method('supports')->willReturn(true);
        $supporting->method('cloneAsDraft')->willReturn($draft);

        $registry = new RevisionClonerRegistry([$unsupporting, $supporting]);

        self::assertSame(
            $draft,
            $registry->cloneAsDraft($source),
        );
    }

    public function testARevisionNoClonerSupportsIsAnError(): void
    {
        $cloner = self::createStub(RevisionClonerInterface::class);
        $cloner->method('supports')->willReturn(false);

        $this->expectException(RuntimeException::class);

        $draft = new RevisionClonerRegistry([$cloner])->cloneAsDraft(
            self::createStub(RevisionInterface::class),
        );
        self::fail('A revision no cloner supports cannot be cloned into a draft: ' . get_debug_type($draft));
    }
}
