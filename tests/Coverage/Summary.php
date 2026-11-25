<?php

declare(strict_types=1);

namespace App\Tests\Coverage;

use RuntimeException;

use function count;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function is_int;
use function is_readable;
use function is_string;
use function json_decode;
use function json_encode;
use function round;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * The counts of one coverage run, in the form stored as the baseline.
 *
 * Line numbers are dropped: a comparison needs only the number of statements per file and how many ran.
 */
final readonly class Summary
{
    /**
     * @param array<string, array{statements: int, covered: int}> $files
     */
    private function __construct(
        public int $statements,
        public int $covered,
        public array $files,
    ) {
    }

    public static function of(Coverage $coverage): self
    {
        return new self(
            $coverage->statements(),
            $coverage->coveredLines(),
            $coverage->perFile(),
        );
    }

    public static function read(string $file): self
    {
        if (
            !is_file($file)
            || !is_readable($file)
        ) {
            throw new RuntimeException(sprintf('No coverage summary at %s.', $file));
        }

        $decoded = json_decode(
            (string) file_get_contents($file),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (
            !is_array($decoded)
            || !is_int($decoded['statements'] ?? null)
            || !is_int($decoded['covered'] ?? null)
            || !is_array($decoded['files'] ?? null)
        ) {
            throw new RuntimeException(sprintf('%s is not a coverage summary.', $file));
        }

        $files = [];
        foreach ($decoded['files'] as $path => $counts) {
            if (
                !is_string($path)
                || !is_array($counts)
                || !is_int($counts['statements'] ?? null)
                || !is_int($counts['covered'] ?? null)
            ) {
                throw new RuntimeException(sprintf('%s is not a coverage summary.', $file));
            }

            $files[$path] = [
                'statements' => $counts['statements'],
                'covered' => $counts['covered'],
            ];
        }

        return new self(
            $decoded['statements'],
            $decoded['covered'],
            $files,
        );
    }

    public function percentage(): float
    {
        return 0 === $this->statements
            ? 0.0
            : 100 * $this->covered / $this->statements;
    }

    public function roundedPercentage(): float
    {
        return round(
            $this->percentage(),
            2,
        );
    }

    public function write(string $file): void
    {
        $json = json_encode(
            [
                'statements' => $this->statements,
                'covered' => $this->covered,
                'files' => $this->files,
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );

        if (
            false === file_put_contents(
                $file,
                $json . "\n",
            )
        ) {
            throw new RuntimeException(sprintf('Could not write %s.', $file));
        }
    }

    public function fileCount(): int
    {
        return count($this->files);
    }
}
