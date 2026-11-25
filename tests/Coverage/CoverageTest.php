<?php

declare(strict_types=1);

namespace App\Tests\Coverage;

use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_PRETTY_PRINT;

/**
 * Reading a Clover report and comparing two summaries.
 *
 * The tests write their own input files. They check that only statements under the source root are counted and that
 * the comparison uses the project total.
 */
final class CoverageTest extends TestCase
{
    private const string CLOVER = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage generated="1">
          <project timestamp="1" name="Clover Coverage">
            <package name="App\Service">
              <file name="/project/src/Service/Runs.php">
                <line num="10" type="method" name="run" visibility="public" complexity="1" crap="1" count="7"/>
                <line num="12" type="stmt" count="3"/>
                <line num="13" type="stmt" count="0"/>
                <line num="14" type="stmt" count="0"/>
              </file>
              <file name="/project/src/Service/Never.php">
                <line num="5" type="stmt" count="0"/>
              </file>
              <file name="/project/tests/Ignored.php">
                <line num="5" type="stmt" count="1"/>
              </file>
            </package>
          </project>
        </coverage>
        XML;

    /** @var list<string> */
    private array $temporaryFiles = [];

    public function testCountsOnlyStatementsInsideTheSourceRoot(): void
    {
        $coverage = Coverage::fromClover(
            $this->clover(),
            '/project/src',
        );

        self::assertSame(
            4,
            $coverage->statements(),
        );
        self::assertSame(
            1,
            $coverage->coveredLines(),
        );
        self::assertSame(
            [
                'Service/Runs.php' => [
                    'statements' => 3,
                    'covered' => 1,
                ],
                'Service/Never.php' => [
                    'statements' => 1,
                    'covered' => 0,
                ],
            ],
            $coverage->perFile(),
        );
    }

    public function testRoundTripsThroughTheSummaryFile(): void
    {
        $coverage = Coverage::fromClover(
            $this->clover(),
            '/project/src',
        );
        $summary = Summary::of($coverage);
        $file = $this->temporaryFile();
        $summary->write($file);

        $read = Summary::read($file);

        self::assertSame(
            $summary->statements,
            $read->statements,
        );
        self::assertSame(
            $summary->covered,
            $read->covered,
        );
        self::assertSame(
            $summary->files,
            $read->files,
        );
        self::assertSame(
            25.0,
            $read->percentage(),
        );
    }

    public function testComparesTheProjectTotalRatherThanAnySingleFile(): void
    {
        $current = Summary::read($this->summaryFile(
            [
                'covered' => 2,
                'files' => [
                    'Service/Runs.php' => [
                        'statements' => 3,
                        'covered' => 2,
                    ],
                    'Service/Never.php' => [
                        'statements' => 1,
                        'covered' => 0,
                    ],
                ],
                'statements' => 4,
            ],
        ));
        $baseline = Summary::read($this->summaryFile(
            [
                'covered' => 4,
                'files' => [
                    'Service/Runs.php' => [
                        'statements' => 3,
                        'covered' => 3,
                    ],
                    'Service/Never.php' => [
                        'statements' => 1,
                        'covered' => 1,
                    ],
                    // A file the current run no longer reports was deleted, so it is skipped.
                    'Service/Deleted.php' => [
                        'statements' => 10,
                        'covered' => 10,
                    ],
                ],
                'statements' => 4,
            ],
        ));

        $comparison = Comparison::of(
            $current,
            $baseline,
        );

        self::assertSame(
            50.0,
            $comparison->drop(),
        );
        self::assertTrue($comparison->exceeds(0.1));
        self::assertFalse($comparison->exceeds(50.0));
        self::assertSame(
            [
                [
                    'file' => 'Service/Never.php',
                    'lost' => 1,
                    'current' => 0.0,
                    'baseline' => 100.0,
                ],
                [
                    'file' => 'Service/Runs.php',
                    'lost' => 1,
                    'current' => 66.67,
                    'baseline' => 100.0,
                ],
            ],
            $comparison->dropped,
        );
    }

    public function testAnIncreasePasses(): void
    {
        $baseline = Summary::read($this->summaryFile(
            [
                'covered' => 1,
                'files' => [
                    'Service/Runs.php' => [
                        'statements' => 4,
                        'covered' => 1,
                    ],
                ],
                'statements' => 4,
            ],
        ));
        $current = Summary::read($this->summaryFile(
            [
                'covered' => 3,
                'files' => [
                    'Service/Runs.php' => [
                        'statements' => 4,
                        'covered' => 3,
                    ],
                ],
                'statements' => 4,
            ],
        ));

        $comparison = Comparison::of(
            $current,
            $baseline,
        );

        self::assertSame(
            -50.0,
            $comparison->drop(),
        );
        self::assertFalse($comparison->exceeds(0.1));
        self::assertSame(
            [],
            $comparison->dropped,
        );
    }

    private function clover(): string
    {
        $file = $this->temporaryFile();
        file_put_contents(
            $file,
            self::CLOVER,
        );

        return $file;
    }

    /**
     * @param array{statements: int, covered: int, files: array<string, array{statements: int, covered: int}>} $summary
     */
    private function summaryFile(array $summary): string
    {
        $file = $this->temporaryFile();
        file_put_contents(
            $file,
            json_encode(
                $summary,
                JSON_PRETTY_PRINT,
            ),
        );

        return $file;
    }

    private function temporaryFile(): string
    {
        $file = tempnam(
            sys_get_temp_dir(),
            'radix-coverage-',
        );
        self::assertIsString($file);

        $this->temporaryFiles[] = $file;

        return $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            unlink($file);
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }
}
