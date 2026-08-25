<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\Application\AbstractRevision;
use App\Entity\Application\RevisableInterface;
use App\Entity\Application\RevisionInterface;
use DateTime;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What one revisable domain has to say about being abandoned. Working out that nobody has touched a revision, that it
 * is still the working head of its aggregate and what to do about it is the same everywhere, and
 * {@see StaleRevisionCleaner} does it once; what only the domain knows is answered here, so a new revisable domain
 * lapses correctly by registering a policy and changing nothing else.
 *
 * Implementations are read by a cron and must be side-effect free: they answer about the state they are handed and
 * remove nothing themselves.
 */
#[AutoconfigureTag('app.stale_revision_policy')]
interface StaleRevisionPolicyInterface
{
    /**
     * The revision entity this policy speaks for.
     *
     * @return class-string<AbstractRevision>
     */
    public function revisionClass(): string;

    /**
     * The moment up to which this revision is kept however long it has sat untouched, or null when nothing about it
     * is dated. Silence about something that has not happened yet says nothing: an activity still to come, a vacancy
     * still open for applications and a poll still being voted on are all being waited on by someone. Once that
     * moment is past, the silence is all there is to go on.
     */
    public function keepUntil(RevisionInterface $revision): ?DateTime;

    /**
     * Why this never-approved aggregate has to stay standing anyway, phrased for a log line, or null when it may go
     * together with its chain. It is the last thing asked before rows are removed, so answer from what the aggregate
     * carries — sign-ups, votes, a sold package — rather than from what it is.
     */
    public function deletionBlockedBy(RevisableInterface $revisable): ?string;

    /**
     * Every stored file path this revision names, so that whatever nothing points at any more can be reclaimed with
     * the row. Paths are content-addressed and a clone carries them forward by value, so several revisions routinely
     * name one file; deletion is reference-checked by {@see FileStorage::remove()}, and a policy simply lists what it
     * sees without deciding whether the bytes may go.
     *
     * @return list<string>
     */
    public function storedPaths(RevisionInterface $revision): array;
}
