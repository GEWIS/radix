<?php

declare(strict_types=1);

namespace App\Service\Application;

use JsonException;
use RuntimeException;

use function dirname;
use function fclose;
use function feof;
use function fgets;
use function fopen;
use function fwrite;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function sprintf;
use function stream_set_write_buffer;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * What the storage migration has already done, so a run that is interrupted can be started again without redoing it.
 *
 * One JSON object per line, appended and flushed as each item is settled. A line is written after the work it
 * describes, so a crash mid-item leaves that item unrecorded and the next run repeats it; every step the migration
 * takes is safe to repeat, which is what makes that the right way round.
 *
 * The outcome matters as much as the fact: an item whose file was missing is still done (its path was rewritten), and
 * one that failed is not, so a later run can be told to retry only the failures.
 */
final class StorageMigrationJournal
{
    /** The path was rewritten and, where there was a file to link, the file was linked. */
    public const string DONE = 'done';

    /** The path was rewritten, but the legacy file it names was not on disk. Not an error: the row still had to move. */
    public const string MISSING_FILE = 'missing-file';

    /** Nothing was changed for this item. A retry may still settle it. */
    public const string FAILED = 'failed';

    /** @var array<string, string> item key to outcome */
    private array $seen = [];

    private bool $loaded = false;

    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    public function __destruct()
    {
        if (null === $this->handle) {
            return;
        }

        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * Whether this item is settled and should be skipped. A failure counts as unsettled when failures are being
     * retried, which is the only way a run ever repeats work it has already recorded.
     */
    public function isSettled(
        string $item,
        bool $retryFailed,
    ): bool {
        $this->load();

        $outcome = $this->seen[$item] ?? null;

        if (null === $outcome) {
            return false;
        }

        return !$retryFailed
            || self::FAILED !== $outcome;
    }

    public function record(
        string $item,
        string $outcome,
        ?string $message = null,
    ): void {
        $this->load();
        $this->seen[$item] = $outcome;

        $line = json_encode(
            [
                'item' => $item,
                'outcome' => $outcome,
                'message' => $message,
            ],
            JSON_THROW_ON_ERROR,
        );

        fwrite(
            $this->appendHandle(),
            $line . "\n",
        );
    }

    /**
     * The journal is appended to once per settled item, which on a run of a hundred thousand photos is a hundred
     * thousand writes; the handle is opened once and held rather than reopened for each. Unbuffered, so that what the
     * journal says has survived a kill is what actually happened.
     *
     * @return resource
     */
    private function appendHandle()
    {
        if (null !== $this->handle) {
            return $this->handle;
        }

        $directory = dirname($this->path);
        if (
            !is_dir($directory)
            && !mkdir(
                $directory,
                0o775,
                true,
            )
            && !is_dir($directory)
        ) {
            throw new RuntimeException(sprintf('Cannot create the journal directory "%s".', $directory));
        }

        $handle = fopen(
            $this->path,
            'ab',
        );
        if (false === $handle) {
            throw new RuntimeException(sprintf('Cannot open the journal "%s".', $this->path));
        }

        stream_set_write_buffer(
            $handle,
            0,
        );

        return $this->handle = $handle;
    }

    /**
     * How many items were recorded with each outcome.
     *
     * @return array<string, int>
     */
    public function tally(): array
    {
        $this->load();

        $tally = [];
        foreach ($this->seen as $outcome) {
            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
        }

        return $tally;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Read the journal once per run. A line that is not readable JSON is skipped rather than fatal: the last line of
     * a journal whose process was killed mid-write is exactly that, and the item it half-describes is simply redone.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $handle = @fopen(
            $this->path,
            'rb',
        );
        if (false === $handle) {
            return;
        }

        while (!feof($handle)) {
            $line = fgets($handle);
            if (
                false === $line
                || '' === trim($line)
            ) {
                continue;
            }

            try {
                $entry = json_decode(
                    $line,
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            } catch (JsonException) {
                continue;
            }

            if (
                !is_array($entry)
                || !is_string($entry['item'] ?? null)
                || !is_string($entry['outcome'] ?? null)
            ) {
                continue;
            }

            $this->seen[$entry['item']] = $entry['outcome'];
        }

        fclose($handle);
    }
}
