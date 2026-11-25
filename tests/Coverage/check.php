<?php

declare(strict_types=1);

use App\Tests\Coverage\Comparison;
use App\Tests\Coverage\Summary;

require dirname(
    __DIR__,
    2,
) . '/vendor/autoload.php';

/*
 * Reports how this run compares with the baseline from the default branch.
 *
 * Files whose coverage fell are annotated and listed in the step summary, and the job stays green. `--fail-on-drop`
 * turns the comparison into a gate that fails above `--allowed-drop` percentage points.
 *
 * Annotations appear on the pull request diff, so the token needs no write permission and nothing is posted.
 */

$options = getopt(
    '',
    [
        'summary:',
        'baseline:',
        'allowed-drop:',
        'fail-on-drop',
    ],
);
if (!is_array($options)) {
    fwrite(
        STDERR,
        "Could not read the options.\n",
    );

    exit(2);
}

// getopt() returns mixed values: a repeated option is a list and a flag without a value is false, so a non-string
// falls back to the default.
$option = static function (array $options, string $name, string $default): string {
    $value = $options[$name] ?? null;

    return is_string($value)
        ? $value
        : $default;
};

$root = dirname(
    __DIR__,
    2,
);

$summaryFile = $option($options, 'summary', $root . '/var/coverage/summary.json');
$baselineFile = $option($options, 'baseline', $root . '/var/coverage/baseline/summary.json');
$allowedDrop = (float) $option($options, 'allowed-drop', '0.1');
$failOnDrop = isset($options['fail-on-drop']);

$current = Summary::read($summaryFile);

printf(
    "Coverage: %s %% (%s of %s statements)\n",
    number_format(
        $current->percentage(),
        2,
    ),
    number_format(
        $current->covered,
        0,
    ),
    number_format(
        $current->statements,
        0,
    ),
);

// A file name comes from the pull request's `src/`, so it is reduced to characters that are safe in a workflow
// command and in markdown.
$path = static fn (string $file): string => (string) preg_replace(
    '#[^A-Za-z0-9_./-]#',
    '?',
    $file,
);

// A workflow command decodes %XX escapes, so `%`, CR and LF are percent-encoded.
$escape = static fn (string $value): string => str_replace(
    [
        '%',
        "\r",
        "\n",
    ],
    [
        '%25',
        '%0D',
        '%0A',
    ],
    $value,
);

$annotate = static function (string $level, string $file, string $body) use ($escape): void {
    printf(
        "::%s file=%s,title=Coverage decreased::%s\n",
        $level,
        $file,
        $escape($body),
    );
};

$writeSummary = static function (array $lines): void {
    $stepSummary = getenv('GITHUB_STEP_SUMMARY');
    if (
        is_string($stepSummary)
        && '' !== $stepSummary
    ) {
        file_put_contents(
            $stepSummary,
            implode(
                "\n",
                $lines,
            ) . "\n",
            FILE_APPEND,
        );

        return;
    }

    printf(
        "-- coverage --\n%s\n",
        implode(
            "\n",
            $lines,
        ),
    );
};

if (!is_file($baselineFile)) {
    printf(
        "No baseline at %s, so there is nothing to compare against. A run on the default branch records one.\n",
        $baselineFile,
    );
    $writeSummary(
        [
            sprintf(
                '## Coverage: %s %%',
                number_format(
                    $current->percentage(),
                    2,
                ),
            ),
            '',
            'No baseline to compare against: a successful run on the default branch records one, and the artifact '
            . 'expires after 30 days.',
            '',
        ],
    );

    exit(0);
}

$comparison = Comparison::of(
    $current,
    Summary::read($baselineFile),
);

printf(
    "Baseline: %s %% (%+.2f percentage points)\n",
    number_format(
        $comparison->baseline,
        2,
    ),
    -$comparison->drop(),
);

$failing = $failOnDrop && $comparison->exceeds($allowedDrop);

// GitHub renders at most ten annotations per step, so the summary lists all files.
foreach (
    array_slice(
        $comparison->dropped,
        0,
        10,
    ) as $file
) {
    $annotate(
        $failing ? 'error' : 'warning',
        $path($file['file']),
        sprintf(
            'coverage fell from %s %% to %s %%, %d lines no longer executed',
            number_format(
                $file['baseline'],
                2,
            ),
            number_format(
                $file['current'],
                2,
            ),
            $file['lost'],
        ),
    );
}

$lines = [
    sprintf(
        '## Coverage: %s %% (was %s %%)',
        number_format(
            $comparison->current,
            2,
        ),
        number_format(
            $comparison->baseline,
            2,
        ),
    ),
    '',
    sprintf(
        '%s percentage points %s.',
        number_format(
            abs($comparison->drop()),
            2,
        ),
        $comparison->drop() > 0.0
            ? sprintf(
                'lower than the baseline, against %s allowed',
                number_format(
                    $allowedDrop,
                    2,
                ),
            )
            : 'higher than the baseline',
    ),
    '',
];

if ([] === $comparison->dropped) {
    $lines[] = 'No file lost coverage.';
    $lines[] = '';
} else {
    $lines[] = '| File | Coverage | Was | Lines lost |';
    $lines[] = '| --- | --- | --- | --- |';
    foreach (
        array_slice(
            $comparison->dropped,
            0,
            25,
        ) as $file
    ) {
        $lines[] = sprintf(
            '| `%s` | %s %% | %s %% | %d |',
            $path($file['file']),
            number_format(
                $file['current'],
                2,
            ),
            number_format(
                $file['baseline'],
                2,
            ),
            $file['lost'],
        );
    }

    $lines[] = '';
    $remaining = count($comparison->dropped) - 25;
    if ($remaining > 0) {
        $lines[] = sprintf(
            '%d more files lost lines.',
            $remaining,
        );
        $lines[] = '';
    }
}

$writeSummary($lines);

if (!$failing) {
    if ($comparison->exceeds($allowedDrop)) {
        printf(
            'Coverage decreased by %s percentage points, more than the %s allowed. This run reports it and does not '
            . "fail: pass --fail-on-drop to make it a gate.\n",
            number_format(
                $comparison->drop(),
                2,
            ),
            number_format(
                $allowedDrop,
                2,
            ),
        );
    } else {
        printf(
            "Coverage is within %s percentage points of the baseline.\n",
            number_format(
                $allowedDrop,
                2,
            ),
        );
    }

    exit(0);
}

printf(
    "Coverage decreased by %s percentage points, more than the %s allowed.\n",
    number_format(
        $comparison->drop(),
        2,
    ),
    number_format(
        $allowedDrop,
        2,
    ),
);

exit(1);
