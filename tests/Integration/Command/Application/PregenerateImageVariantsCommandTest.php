<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Application;

use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use App\Service\Application\FileStorage;
use App\Service\Application\VariantGenerator;
use App\Tests\Integration\DatabaseTestCase;

use function dirname;

/** Storage is the in-memory adapter, so the walk only sees what each test stores. */
final class PregenerateImageVariantsCommandTest extends DatabaseTestCase
{
    public function testGeneratesTheMissingVariantsOfAStoredPhoto(): void
    {
        $source = $this->storeSource(StorageNamespace::PhotoOriginal);

        $this->executeCommand(['--delay' => '0']);

        $generator = $this->variantGenerator();
        self::assertTrue($generator->variantExists(
            $source,
            ImageVariant::W320,
        ));
        self::assertTrue($generator->variantExists(
            $source,
            ImageVariant::W640,
        ));
        // The 800px original cannot fill a 960px target, and pre-generation never upscales.
        self::assertFalse($generator->variantExists(
            $source,
            ImageVariant::W960,
        ));
    }

    public function testCompanyImageWithoutABannerPackageIsTreatedAsALogo(): void
    {
        $source = $this->storeSource(StorageNamespace::CompanyImage);

        $this->executeCommand(['--delay' => '0']);

        $generator = $this->variantGenerator();
        self::assertTrue($generator->variantExists(
            $source,
            ImageVariant::W320,
        ));
        self::assertTrue($generator->variantExists(
            $source,
            ImageVariant::W640,
        ));
        self::assertFalse($generator->variantExists(
            $source,
            ImageVariant::Leaderboard,
        ));
    }

    public function testDryRunWritesNothing(): void
    {
        $source = $this->storeSource(StorageNamespace::PhotoOriginal);

        $this->executeCommand(['--dry-run' => true]);

        self::assertFalse($this->variantGenerator()->variantExists(
            $source,
            ImageVariant::W320,
        ));
    }

    public function testPrefixBoundsTheRun(): void
    {
        $photo = $this->storeSource(StorageNamespace::PhotoOriginal);
        $logo = $this->storeSource(StorageNamespace::CompanyImage);

        $this->executeCommand([
            '--delay' => '0',
            '--prefix' => 'career/',
        ]);

        $generator = $this->variantGenerator();
        self::assertFalse($generator->variantExists(
            $photo,
            ImageVariant::W320,
        ));
        self::assertTrue($generator->variantExists(
            $logo,
            ImageVariant::W320,
        ));
    }

    /** @param array<string, mixed> $input */
    private function executeCommand(array $input = []): void
    {
        $this->assertCommandIsSuccessful(static::runCommand(
            'app:image:pregenerate',
            $input,
        ));
    }

    private function variantGenerator(): VariantGenerator
    {
        return self::getContainer()->get(VariantGenerator::class);
    }

    private function storeSource(StorageNamespace $namespace): string
    {
        return self::getContainer()->get(FileStorage::class)->store(
            $namespace,
            dirname(
                __DIR__,
                4,
            ) . '/tests/Resources/images/gala-dinner-1.jpg',
            '1',
        )->path;
    }
}
