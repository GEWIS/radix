<?php

declare(strict_types=1);

namespace App\Tests\Coverage;

use RuntimeException;
use SimpleXMLElement;

use function count;
use function is_array;
use function is_file;
use function rtrim;
use function simplexml_load_file;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Line coverage of one source tree, from PHPUnit's Clover report.
 *
 * The report contains every file in the source filter, so a class no test loads counts as uncovered and the
 * denominator is the whole tree.
 */
final class Coverage
{
    /** @var array<string, array<int, true>> executable line numbers, keyed by path relative to the source root */
    private array $statements = [];

    /** @var array<string, array<int, true>> executed line numbers, keyed by path relative to the source root */
    private array $covered = [];

    /**
     * @param string $sourceRoot the directory the report was generated for, which the report names in absolute paths
     */
    public static function fromClover(
        string $cloverFile,
        string $sourceRoot,
    ): self {
        if (!is_file($cloverFile)) {
            throw new RuntimeException(sprintf(
                'No Clover report at %s. Run PHPUnit with --coverage-clover first.',
                $cloverFile,
            ));
        }

        $xml = simplexml_load_file($cloverFile);
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException(sprintf('Could not read %s as XML.', $cloverFile));
        }

        $files = $xml->xpath('//file');
        if (!is_array($files)) {
            throw new RuntimeException(sprintf('Could not read the files from %s.', $cloverFile));
        }

        $coverage = new self();
        $prefix = rtrim(
            $sourceRoot,
            '/',
        ) . '/';

        foreach ($files as $file) {
            $name = (string) $file['name'];
            if (
                !str_starts_with(
                    $name,
                    $prefix,
                )
            ) {
                continue;
            }

            $relative = substr(
                $name,
                strlen($prefix),
            );

            $lines = $file->line;
            if (null === $lines) {
                continue;
            }

            foreach ($lines as $line) {
                // Clover lists a method's declaration line too; only `stmt` lines are statements.
                if ('stmt' !== (string) $line['type']) {
                    continue;
                }

                $number = (int) $line['num'];
                $coverage->statements[$relative][$number] = true;
                if ((int) $line['count'] <= 0) {
                    continue;
                }

                $coverage->covered[$relative][$number] = true;
            }
        }

        return $coverage;
    }

    public function statements(): int
    {
        $total = 0;
        foreach ($this->statements as $lines) {
            $total += count($lines);
        }

        return $total;
    }

    public function coveredLines(): int
    {
        $total = 0;
        foreach ($this->covered as $lines) {
            $total += count($lines);
        }

        return $total;
    }

    /**
     * @return array<string, array{statements: int, covered: int}>
     */
    public function perFile(): array
    {
        $files = [];
        foreach ($this->statements as $file => $lines) {
            $files[$file] = [
                'statements' => count($lines),
                'covered' => isset($this->covered[$file]) ? count($this->covered[$file]) : 0,
            ];
        }

        return $files;
    }
}
