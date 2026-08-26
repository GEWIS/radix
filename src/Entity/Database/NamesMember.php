<?php

declare(strict_types=1);

namespace App\Entity\Database;

/**
 * A sub-decision that names a member of its own.
 *
 * The distinction this draws is the one the `lidnr` column draws: a discharge or a withdrawal answers who it is about
 * as well, but by asking the installation or the granting it undoes, and it is that decision that names them. Only
 * the ones marked here keep a member on the record, which is what decides whether a decision may be removed once that
 * member has been deleted.
 */
interface NamesMember
{
    /**
     * The member this sub-decision names. Null only where the register never knew who it was, which is the budgets
     * and financial statements from before BV 1209.
     */
    public function getMember(): ?Member;
}
