<?php

declare(strict_types=1);

use App\Tests\Coverage\Coverage;
use App\Tests\Coverage\Summary;

require dirname(
    __DIR__,
    2,
) . '/vendor/autoload.php';

/*
 * Turns the ordinary suite's Clover report into the baseline summary.
 *
 * The browser suite is not measured; the logic behind a workflow belongs in a unit or integration test.
 */

$root = dirname(
    __DIR__,
    2,
);
$options = getopt(
    '',
    [
        'clover:',
        'source:',
        'out:',
    ],
);
if (!is_array($options)) {
    fwrite(
        STDERR,
        "Could not read the options.\n",
    );

    exit(2);
}

// getopt() returns mixed values and a repeated option is a list, so a non-string falls back to the default.
$option = static function (array $options, string $name, string $default): string {
    $value = $options[$name] ?? null;

    return is_string($value)
        ? $value
        : $default;
};

$coverage = Coverage::fromClover(
    $option($options, 'clover', $root . '/var/coverage/clover.xml'),
    $option($options, 'source', $root . '/src'),
);

$summary = Summary::of($coverage);
$summary->write($option($options, 'out', $root . '/var/coverage/summary.json'));

printf(
    "Coverage: %s %% (%s of %s statements in %s files)\n",
    number_format(
        $summary->percentage(),
        2,
    ),
    number_format(
        $summary->covered,
        0,
    ),
    number_format(
        $summary->statements,
        0,
    ),
    number_format(
        $summary->fileCount(),
        0,
    ),
);
