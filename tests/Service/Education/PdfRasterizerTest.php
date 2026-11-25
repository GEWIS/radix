<?php

declare(strict_types=1);

namespace App\Tests\Service\Education;

use App\Service\Education\PdfRasterizer;
use App\Service\Education\PdfRasterizerException;
use Override;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function count;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sprintf;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The rasterizer is the first half of the pipeline that never serves the uploaded PDF: it runs poppler over the file.
 * These tests use a hand-written one-page PDF so no fixture and no network are involved.
 */
final class PdfRasterizerTest extends TestCase
{
    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pdf-rasterizer-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    #[Override]
    protected function tearDown(): void
    {
        $entries = scandir($this->directory);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            $path = $this->directory . '/' . $entry;
            if (is_dir($path)) {
                continue;
            }

            @unlink($path);
        }

        @rmdir($this->directory);
    }

    public function testThePageCountIsReadFromTheDocument(): void
    {
        $pdf = $this->pdf();

        self::assertSame(
            1,
            new PdfRasterizer()->pageCount($pdf),
        );

        @unlink($pdf);
    }

    public function testEachPageIsRenderedToItsOwnJpegInPageOrder(): void
    {
        $pdf = $this->pdf();

        $pages = new PdfRasterizer()->rasterize(
            $pdf,
            $this->directory,
            PdfRasterizer::DPI_DIGITAL,
        );

        self::assertCount(
            1,
            $pages,
        );
        self::assertStringStartsWith(
            $this->directory . '/page-',
            $pages[0],
        );
        self::assertStringEndsWith(
            '.jpg',
            $pages[0],
        );
        self::assertNotEmpty(glob($this->directory . '/page-*.jpg'));

        @unlink($pdf);
    }

    public function testSomethingThatIsNotAPdfCannotBeMeasured(): void
    {
        $file = $this->textFile();

        $this->expectException(PdfRasterizerException::class);
        new PdfRasterizer()->pageCount($file);
    }

    public function testSomethingThatIsNotAPdfCannotBeRendered(): void
    {
        $file = $this->textFile();

        try {
            $this->expectException(PdfRasterizerException::class);
            new PdfRasterizer()->rasterize(
                $file,
                $this->directory,
                PdfRasterizer::DPI_SCANNED,
            );
        } finally {
            @unlink($file);
        }
    }

    private function textFile(): string
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'pdf-rasterizer-source-',
        );
        self::assertIsString($path);
        file_put_contents(
            $path,
            'this is not a pdf',
        );

        return $path;
    }

    /**
     * A one-page PDF with a real cross-reference table, which is what poppler insists on.
     */
    private function pdf(): string
    {
        $objects = [
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
            '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
            '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $startxref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf(
                "%010d 00000 n \n",
                $offset,
            );
        }

        $pdf .= 'trailer<</Size ' . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n"
            . $startxref . "\n%%EOF\n";

        $path = tempnam(
            sys_get_temp_dir(),
            'pdf-rasterizer-',
        );
        self::assertIsString($path);
        file_put_contents(
            $path,
            $pdf,
        );

        return $path;
    }
}
