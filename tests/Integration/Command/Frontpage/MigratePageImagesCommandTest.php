<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Frontpage;

use App\Entity\Frontpage\Page;
use App\Repository\Frontpage\PageRepository;
use App\Service\Application\FileStorage;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Console\Tester\ExecutionResult;

use function base64_decode;
use function count;
use function strval;

final class MigratePageImagesCommandTest extends DatabaseTestCase
{
    private const string PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const string LEGACY = 'pages/images/0f0f0f0f.png';

    public function testAnOldPageHasItsImagesFiledUnderIt(): void
    {
        $page = $this->aPage(0);
        $this->show(
            $page,
            self::LEGACY,
        );
        $this->storage()->write(
            self::LEGACY,
            $this->pngBytes(),
        );

        $this->migrate();

        $filed = 'pages/images/' . $page->getId() . '/0f0f0f0f.png';
        self::assertTrue($this->storage()->exists($filed));
        self::assertFalse($this->storage()->exists(self::LEGACY));
        self::assertStringContainsString(
            $filed,
            strval($page->getContent()->getValueEN()),
        );
        self::assertStringContainsString(
            $filed,
            strval($page->getContent()->getValueNL()),
        );
    }

    public function testAFileTwoPagesShowIsCopiedToBothBeforeItGoes(): void
    {
        $first = $this->aPage(0);
        $second = $this->aPage(1);

        $this->show(
            $first,
            self::LEGACY,
        );
        $this->show(
            $second,
            self::LEGACY,
        );
        $this->storage()->write(
            self::LEGACY,
            $this->pngBytes(),
        );

        $this->migrate();

        self::assertTrue($this->storage()->exists('pages/images/' . $first->getId() . '/0f0f0f0f.png'));
        self::assertTrue($this->storage()->exists('pages/images/' . $second->getId() . '/0f0f0f0f.png'));
        self::assertFalse($this->storage()->exists(self::LEGACY));
    }

    public function testADryRunLeavesEverythingAsItIs(): void
    {
        $page = $this->aPage(0);
        $this->show(
            $page,
            self::LEGACY,
        );
        $this->storage()->write(
            self::LEGACY,
            $this->pngBytes(),
        );

        $result = $this->migrate(true);

        self::assertStringContainsString(
            'Nothing was written',
            $result->getDisplay(),
        );
        self::assertTrue($this->storage()->exists(self::LEGACY));
        self::assertStringContainsString(
            self::LEGACY,
            strval($page->getContent()->getValueEN()),
        );
    }

    public function testAPageNamingAFileThatIsGoneIsLeftAlone(): void
    {
        $page = $this->aPage(0);
        $this->show(
            $page,
            self::LEGACY,
        );

        $result = $this->migrate();

        self::assertStringContainsString(
            'not in storage',
            $result->getDisplay(),
        );
        self::assertStringContainsString(
            self::LEGACY,
            strval($page->getContent()->getValueEN()),
        );
    }

    private function migrate(bool $dryRun = false): ExecutionResult
    {
        $input = [];

        if ($dryRun) {
            $input['--dry-run'] = true;
        }

        $result = static::runCommand(
            'app:page:migrate-images',
            $input,
            interactive: false,
        );
        $this->assertCommandIsSuccessful($result);

        return $result;
    }

    private function show(
        Page $page,
        string $path,
    ): void {
        $page->getContent()->updateValues(
            '<p><img src="/img/w1280/' . $path . '"></p>',
            '<p><img src="/img/w640/' . $path . '"></p>',
        );

        $this->entityManager->flush();
    }

    private function aPage(int $index): Page
    {
        $pages = self::getContainer()->get(PageRepository::class)->findAll();
        self::assertGreaterThan(
            $index,
            count($pages),
        );

        return $pages[$index];
    }

    private function storage(): FileStorage
    {
        return self::getContainer()->get(FileStorage::class);
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(
            self::PNG_BASE64,
            true,
        );
        self::assertIsString($bytes);

        return $bytes;
    }
}
