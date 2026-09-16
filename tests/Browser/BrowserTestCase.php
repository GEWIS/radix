<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Override;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Panther\PantherTestCase;

use function getenv;
use function is_dir;
use function is_writable;
use function mkdir;
use function sprintf;

/**
 * A test that drives a real browser against a real server.
 *
 * These cover the behaviour the rest of the suite cannot reach: what a page does once its JavaScript has run.
 * Whether an editor survives a cache restore, whether a lock is released on navigation, whether a live component
 * still responds after one. None of that is visible to a test that only renders a template.
 *
 * They are in the `browser` group and excluded from the default run, because each one starts Chromium and a web
 * server. Run them with `make test-browser`.
 *
 * Two things are different here from every other test in this suite, and both are consequences of the server being a
 * separate process:
 *
 * `dama/doctrine-test-bundle` wraps each test in a transaction on this process's connection. The server has its own,
 * so it cannot see anything written here, and nothing it writes is rolled back. Read the seeded fixtures rather than
 * building data, exactly as {@see \App\Tests\Integration\DatabaseTestCase} asks, and treat anything a test does write
 * as permanent: `make test-prepare` is what puts the seed back.
 *
 * The browser signs in through the real form, because {@see \App\Tests\Support\SignsInThroughTheKernel} authenticates
 * through container services in this process and none of that reaches the server.
 */
#[Group('browser')]
abstract class BrowserTestCase extends PantherTestCase
{
    /**
     * Chromium writes its profile into `XDG_CONFIG_HOME` and `XDG_DATA_HOME`. The application image points both at
     * Caddy's directories, which are owned by root while the container runs as `nonroot`, and Chromium then exits
     * before the first page with no usable diagnostic. `make test-browser` points both at a writable directory; this
     * creates them and fails with a readable message otherwise, because the cause is not apparent from the failure.
     */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        foreach (
            [
                'XDG_CONFIG_HOME',
                'XDG_DATA_HOME',
            ] as $variable
        ) {
            $directory = getenv($variable);
            if (
                false === $directory
                || '' === $directory
            ) {
                continue;
            }

            if (
                !is_dir($directory)
                && !mkdir(
                    $directory,
                    0o700,
                    true,
                )
                && !is_dir($directory)
            ) {
                throw new RuntimeException(sprintf(
                    'Could not create %s, which %s names for the browser to write to.',
                    $directory,
                    $variable,
                ));
            }

            if (is_writable($directory)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                '%s names %s, which cannot be written to, so the browser will not start. Run these through '
                . '`make test-browser`, which points it somewhere that can.',
                $variable,
                $directory,
            ));
        }
    }
}
