<?php

declare(strict_types=1);

namespace App\Tests\Command\Frontpage;

use App\Command\Frontpage\SanitisePagesCommand;
use App\Entity\Frontpage\FrontpageLocalisedText;
use App\Entity\Frontpage\Page;
use App\Repository\Frontpage\PageRepository;
use App\Service\Frontpage\PageContentSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

use function str_replace;

/**
 * A one-off pass over pages written before saving sanitised content. Only pages the sanitiser would change are
 * rewritten, in both languages, and a dry run reports them without writing.
 */
#[CoversClass(SanitisePagesCommand::class)]
final class SanitisePagesCommandTest extends TestCase
{
    public function testRewritesOnlyThePagesThatWouldChange(): void
    {
        $scripted = $this->page(
            12,
            '<p>Hoi</p><script>alert(1)</script>',
            '<p>Hallo</p><script>alert(1)</script>',
        );
        $clean = $this->page(
            13,
            '<p>Hoi</p>',
            '<p>Hallo</p>',
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new SanitisePagesCommand(
            $this->pages(
                $scripted,
                $clean,
            ),
            $this->sanitizer(),
            $entityManager,
        ));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );

        self::assertSame(
            '<p>Hoi</p>',
            $scripted->content->getValueEN(),
        );
        self::assertSame(
            '<p>Hallo</p>',
            $scripted->content->getValueNL(),
        );
        self::assertSame(
            '<p>Hoi</p>',
            $clean->content->getValueEN(),
            'A page the sanitizer leaves alone is not rewritten.',
        );

        $display = $tester->getDisplay();
        self::assertStringContainsString(
            'Page #12 contains markup that is not allowed.',
            $display,
        );
        self::assertStringNotContainsString(
            'Page #13',
            $display,
        );
        self::assertStringContainsString(
            '1 page(s) were rewritten.',
            $display,
        );
    }

    public function testADryRunReportsButWritesNothing(): void
    {
        $scripted = $this->page(
            12,
            '<p>Hoi</p><script>alert(1)</script>',
            '<p>Hallo</p>',
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $tester = new CommandTester(new SanitisePagesCommand(
            $this->pages($scripted),
            $this->sanitizer(),
            $entityManager,
        ));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute(['--dry-run' => true]),
        );

        self::assertStringContainsString(
            '<script>',
            (string) $scripted->content->getValueEN(),
            'A dry run leaves the markup where it was.',
        );
        self::assertStringContainsString(
            'Page #12 contains markup that is not allowed.',
            $tester->getDisplay(),
        );
        self::assertStringContainsString(
            'Nothing was written.',
            $tester->getDisplay(),
        );
    }

    private function pages(Page ...$pages): PageRepository
    {
        $repository = self::createStub(PageRepository::class);
        $repository->method('findAll')->willReturn($pages);

        return $repository;
    }

    /**
     * The real sanitiser runs over a stand-in for the configured one, so the test covers the command's decision and
     * not the configured tags.
     */
    private function sanitizer(): PageContentSanitizer
    {
        $sanitizer = self::createStub(HtmlSanitizerInterface::class);
        $sanitizer->method('sanitize')->willReturnCallback(
            static fn (string $content): string => str_replace(
                '<script>alert(1)</script>',
                '',
                $content,
            ),
        );

        return new PageContentSanitizer($sanitizer);
    }

    private function page(
        int $id,
        string $english,
        string $dutch,
    ): Page {
        $page = new Page();
        new ReflectionProperty(
            Page::class,
            'id',
        )->setValue(
            $page,
            $id,
        );
        $page->content = new FrontpageLocalisedText(
            $english,
            $dutch,
        );

        return $page;
    }
}
