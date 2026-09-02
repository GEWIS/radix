<?php

declare(strict_types=1);

namespace App\Service\Application;

/**
 * A domain's objection to removing a never-approved aggregate, and whether an operator may overrule it.
 *
 * Most objections are about someone else's record: a package a company was sold, an account a representative still
 * signs in with, a vote somebody cast on a poll. Those are not the cleanup's to throw away at any prompting, and they
 * say so with {@see self::hard()}.
 *
 * A {@see self::forceable()} objection is one the domain raises because the state is not supposed to occur, rather
 * than because something valuable hangs on it. Sign-ups on an activity nobody ever approved are the case this exists
 * for: they cannot be reached from anywhere in the site, so the activity sits in the skipped column of every nightly
 * run and stays there. An operator who has looked at what is left may pass `--force` to have it go with the rest.
 */
final readonly class StaleRevisionDeletionBlock
{
    private function __construct(
        /** Why the aggregate has to stay standing, phrased to follow "kept because" in a log line. */
        public string $reason,
        /** Whether a run told to force may remove the aggregate anyway. */
        public bool $forceable,
    ) {
    }

    /**
     * An objection no run overrules, because removing the aggregate would take something with it that is not the
     * cleanup's to remove.
     */
    public static function hard(string $reason): self
    {
        return new self(
            $reason,
            false,
        );
    }

    /**
     * An objection a run given `--force` may overrule.
     */
    public static function forceable(string $reason): self
    {
        return new self(
            $reason,
            true,
        );
    }
}
