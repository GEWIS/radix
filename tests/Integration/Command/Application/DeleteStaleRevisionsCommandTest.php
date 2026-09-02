<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Application;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupFieldValue;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupOption;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Application\RevisionInterface;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Entity\Decision\Member;
use App\Entity\Decision\OrganInformation;
use App\Entity\Decision\OrganInformationRevision;
use App\Entity\Frontpage\Poll;
use App\Entity\Frontpage\PollRevision;
use App\Entity\User\CompanyUser;
use App\Service\Application\FileStorage;
use App\Tests\Integration\DatabaseTestCase;
use App\Workflow\RevisionClonerRegistry;
use DateTime;
use Doctrine\DBAL\Types\Types;

/**
 * The stale-revision cleanup is a GDPR cron that reaches every revisable domain, so its branches are pinned end to end
 * against a real database and the storage a run reclaims from: an abandoned re-edit is discarded back to the live
 * version, a never-approved aggregate is removed whole, a subject that has not happened yet is left alone whatever the
 * silence around it, a domain that says no is obeyed, and a dry run reports without touching anything.
 *
 * Staleness is forced by backdating the head's (auto-stamped) `updatedAt` with a DQL update, which is the only way to
 * write it; the same helper moves the dates the domains judge relevance by, so a case never flushes its way past the
 * ageing it just arranged.
 */
final class DeleteStaleRevisionsCommandTest extends DatabaseTestCase
{
    public function testRevertsAStaleReEditToItsApprovedRevision(): void
    {
        $activity = $this->anApprovedActivityWithoutSignupLists();
        $live = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $live,
        );

        // An abandoned re-edit: a Draft head spawned from the live revision, untouched for longer than the cutoff.
        $draft = $this->cloneAsDraft($live);
        $draftId = (int) $draft->getId();
        $this->backdate(
            ActivityRevision::class,
            $draftId,
            [
                'beginTime' => new DateTime('-2 months'),
                'endTime' => new DateTime('-2 months +1 hour'),
                'updatedAt' => new DateTime('-40 days'),
            ],
        );

        $this->executeCommand();

        // The activity is back on its approved revision and the abandoned draft is gone.
        self::assertSame(
            $live,
            $activity->getCurrentRevision(),
        );
        self::assertNull(
            $this->entityManager->getRepository(ActivityRevision::class)->find($draftId),
        );
    }

    public function testDeletesAStaleNeverApprovedActivityEntirely(): void
    {
        $draft = $this->aNeverApprovedActivityDraft();
        $activityId = (int) $draft->getActivity()->getId();
        $this->backdate(
            ActivityRevision::class,
            (int) $draft->getId(),
            [
                'beginTime' => new DateTime('-2 months'),
                'endTime' => new DateTime('-2 months +1 hour'),
                'updatedAt' => new DateTime('-40 days'),
            ],
        );

        $this->executeCommand();

        // With no live revision to fall back to, the whole activity (every revision in its chain) is removed.
        self::assertNull(
            $this->entityManager->getRepository(Activity::class)->find($activityId),
        );
    }

    public function testDryRunReportsButChangesNothing(): void
    {
        $draft = $this->aNeverApprovedActivityDraft();
        $activityId = (int) $draft->getActivity()->getId();
        $draftId = (int) $draft->getId();
        $this->backdate(
            ActivityRevision::class,
            $draftId,
            [
                'beginTime' => new DateTime('-2 months'),
                'endTime' => new DateTime('-2 months +1 hour'),
                'updatedAt' => new DateTime('-40 days'),
            ],
        );

        $this->executeCommand(['--dry-run' => true]);

        // The stale draft and its activity are reported but left in place.
        self::assertNotNull(
            $this->entityManager->getRepository(Activity::class)->find($activityId),
        );
        self::assertNotNull(
            $this->entityManager->getRepository(ActivityRevision::class)->find($draftId),
        );
    }

    /**
     * A head with the board is nobody's turn in particular, but a month of silence about an evening that has since
     * come and gone says the same thing as a month of silence from an author.
     */
    public function testAHeadTheBoardNeverGotToLapsesOnTheSameCutoff(): void
    {
        $activity = $this->anApprovedActivityWithoutSignupLists();
        $live = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $live,
        );

        $submitted = $this->cloneAsDraft($live);
        $submitted->setStatus(RevisionStatus::Submitted);
        $this->entityManager->flush();
        $submittedId = (int) $submitted->getId();
        $this->backdate(
            ActivityRevision::class,
            $submittedId,
            [
                'beginTime' => new DateTime('-2 months'),
                'endTime' => new DateTime('-2 months +1 hour'),
                'updatedAt' => new DateTime('-40 days'),
            ],
        );

        $this->executeCommand();

        self::assertSame(
            $live,
            $activity->getCurrentRevision(),
        );
        self::assertNull(
            $this->entityManager->getRepository(ActivityRevision::class)->find($submittedId),
        );
    }

    /**
     * Silence about something that has not happened yet says nothing about whether anyone still wants it, so the
     * cutoff only starts to count once the activity itself is over.
     */
    public function testKeepsAnUntouchedHeadWhoseActivityIsStillToCome(): void
    {
        $draft = $this->aNeverApprovedActivityDraft();
        $activityId = (int) $draft->getActivity()->getId();
        $draftId = (int) $draft->getId();
        $this->backdate(
            ActivityRevision::class,
            $draftId,
            [
                'beginTime' => new DateTime('+2 weeks'),
                'endTime' => new DateTime('+2 weeks +1 hour'),
                'updatedAt' => new DateTime('-100 days'),
            ],
        );

        $this->executeCommand();
        self::assertNotNull(
            $this->entityManager->getRepository(Activity::class)->find($activityId),
            'An activity that is still ahead of us is not abandoned, however long it has sat untouched.',
        );

        // Once it has been and gone, the same silence does count.
        $this->backdate(
            ActivityRevision::class,
            $draftId,
            [
                'beginTime' => new DateTime('-2 weeks'),
                'endTime' => new DateTime('-2 weeks +1 hour'),
            ],
        );
        $this->executeCommand();

        self::assertNull(
            $this->entityManager->getRepository(Activity::class)->find($activityId),
        );
    }

    /**
     * The same rule in a domain that dates itself differently: a posting still open for applications is being waited
     * on by whoever might yet apply, and only lapses once it has closed.
     */
    public function testKeepsAStaleVacancyDraftWhileThePostingIsStillOpen(): void
    {
        $vacancy = $this->anApprovedVacancy();
        $live = $vacancy->getCurrentRevision();
        self::assertInstanceOf(
            VacancyRevision::class,
            $live,
        );

        $draft = $this->cloneAsDraft($live);
        $draftId = (int) $draft->getId();
        $this->backdate(
            VacancyRevision::class,
            $draftId,
            [
                'endDate' => new DateTime('+2 weeks'),
                'updatedAt' => new DateTime('-100 days'),
            ],
        );

        $this->executeCommand();
        self::assertNotNull(
            $this->entityManager->getRepository(VacancyRevision::class)->find($draftId),
            'A vacancy still open for applications is not abandoned.',
        );

        $this->backdate(
            VacancyRevision::class,
            $draftId,
            ['endDate' => new DateTime('-1 day')],
        );
        $this->executeCommand();

        self::assertSame(
            $live,
            $vacancy->getCurrentRevision(),
        );
        self::assertNull(
            $this->entityManager->getRepository(VacancyRevision::class)->find($draftId),
        );
    }

    /**
     * A question the board never approved has nothing behind it to fall back to, so the poll goes with the revision
     * that asked it.
     */
    public function testDeletesAPollThatWasNeverApproved(): void
    {
        $revision = $this->aNeverApprovedPollRevision();
        $pollId = (int) $revision->getRevisable()->getId();
        $revisionId = (int) $revision->getId();
        $this->backdate(
            PollRevision::class,
            $revisionId,
            ['updatedAt' => new DateTime('-40 days')],
        );

        $this->executeCommand();

        self::assertNull(
            $this->entityManager->getRepository(Poll::class)->find($pollId),
        );
        self::assertNull(
            $this->entityManager->getRepository(PollRevision::class)->find($revisionId),
        );
    }

    /**
     * Removing the revision that named a file is what makes the bytes reclaimable, and only that: a logo the live
     * version still shows is named by two revisions, and losing one of them changes nothing about it.
     */
    public function testReclaimsOnlyTheFilesNoRevisionStillNames(): void
    {
        $shared = 'career/1/images/shared-with-the-live-version.png';
        $abandoned = 'career/1/images/only-the-abandoned-draft-names-this.png';

        $company = $this->anApprovedCompany();
        $live = $company->getCurrentRevision();
        self::assertInstanceOf(
            CompanyRevision::class,
            $live,
        );
        $live->setSquareLogo($shared);
        $this->entityManager->flush();

        // The clone carries the square logo forward by value; the banner is this draft's alone.
        $draft = $this->cloneAsDraft($live);
        self::assertInstanceOf(
            CompanyRevision::class,
            $draft,
        );
        $draft->setBannerLogo($abandoned);
        $this->entityManager->flush();
        $draftId = (int) $draft->getId();
        $this->backdate(
            CompanyRevision::class,
            $draftId,
            ['updatedAt' => new DateTime('-40 days')],
        );

        $fileStorage = $this->fileStorage();
        $fileStorage->write(
            $shared,
            'shared',
        );
        $fileStorage->write(
            $abandoned,
            'abandoned',
        );

        $this->executeCommand();

        self::assertNull(
            $this->entityManager->getRepository(CompanyRevision::class)->find($draftId),
        );
        self::assertFalse(
            $fileStorage->exists($abandoned),
            'Nothing names the banner the abandoned draft carried any more, so its bytes go with it.',
        );
        self::assertTrue(
            $fileStorage->exists($shared),
            'The live version still shows this logo.',
        );
    }

    /**
     * A body's page names four files at once: an upload and the cut made from it, twice over. All four are offered
     * back to storage, or a page that was thrown away keeps half of itself on disk forever.
     */
    public function testReclaimsEveryImageAnAbandonedBodyPageNamed(): void
    {
        $paths = [
            'organs/images/abandoned-banner-source.png',
            'organs/images/abandoned-banner.png',
            'organs/images/abandoned-logo-source.png',
            'organs/images/abandoned-logo.png',
        ];

        $page = $this->anApprovedOrganPage();
        $live = $page->getCurrentRevision();
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $live,
        );

        $draft = $this->cloneAsDraft($live);
        self::assertInstanceOf(
            OrganInformationRevision::class,
            $draft,
        );
        $draft->setBannerSource($paths[0]);
        $draft->setBannerPath($paths[1]);
        $draft->setLogoSource($paths[2]);
        $draft->setLogoPath($paths[3]);
        $this->entityManager->flush();
        $draftId = (int) $draft->getId();
        $this->backdate(
            OrganInformationRevision::class,
            $draftId,
            ['updatedAt' => new DateTime('-40 days')],
        );

        $fileStorage = $this->fileStorage();
        foreach ($paths as $path) {
            $fileStorage->write(
                $path,
                'image',
            );
        }

        $this->executeCommand();

        self::assertNull(
            $this->entityManager->getRepository(OrganInformationRevision::class)->find($draftId),
        );

        foreach ($paths as $path) {
            self::assertFalse(
                $fileStorage->exists($path),
                $path . ' should have been reclaimed with the revision that named it.',
            );
        }
    }

    /**
     * The last guard before an aggregate goes is the domain's own. A company that was sold something is a real
     * arrangement whatever its profile looks like, and the database would take its accounts and its timeline with it.
     */
    public function testKeepsANeverApprovedCompanyThatWasAlreadySoldAPackage(): void
    {
        $company = $this->anApprovedCompanyWithAPackage();
        $live = $company->getCurrentRevision();
        self::assertInstanceOf(
            CompanyRevision::class,
            $live,
        );

        // Made to look like a company nobody ever approved: a draft head, and nothing live behind it.
        $draft = $this->cloneAsDraft($live);
        $company->setLiveRevision(null);
        $this->entityManager->flush();
        $companyId = (int) $company->getId();
        $draftId = (int) $draft->getId();
        $this->backdate(
            CompanyRevision::class,
            $draftId,
            ['updatedAt' => new DateTime('-100 days')],
        );

        $this->executeCommand();

        self::assertNotNull(
            $this->entityManager->getRepository(Company::class)->find($companyId),
        );
        self::assertNotNull(
            $this->entityManager->getRepository(CompanyRevision::class)->find($draftId),
        );
    }

    /**
     * Sign-ups belong to a live revision's lists, so sign-ups on an activity nobody ever approved are a state that is
     * not supposed to arise. A scheduled run will not guess at them: it skips the activity and says why, every night.
     */
    public function testKeepsAStaleActivityThatSomehowHasSignups(): void
    {
        $draft = $this->aNeverApprovedActivityDraftWithASignup();
        $activityId = (int) $draft->getActivity()->getId();

        $this->executeCommand();

        self::assertNotNull(
            $this->entityManager->getRepository(Activity::class)->find($activityId),
        );
    }

    /**
     * Forced, the same activity goes, and takes its lists, its sign-ups and the answers on them with it. The sign-ups
     * are the point: they are not reachable from anywhere on the site once the activity they hang off was never
     * approved, so an operator who has read a dry run may clear them out rather than have them skipped for good.
     */
    public function testForceDeletesAStaleActivityTogetherWithItsSignups(): void
    {
        $draft = $this->aNeverApprovedActivityDraftWithASignup();
        $activityId = (int) $draft->getActivity()->getId();
        $signupId = (int) $this->theSignupOn($draft)->getId();

        $this->executeCommand(['--force' => true]);

        self::assertNull(
            $this->entityManager->getRepository(Activity::class)->find($activityId),
        );
        self::assertNull(
            $this->entityManager->getRepository(Signup::class)->find($signupId),
        );
        // The answer rows name a field and an option that go in the same flush, so the removal is only correct if
        // they are unlinked in the right order; a stray answer would fail the foreign key rather than survive.
        self::assertSame(
            0,
            $this->countFieldValuesFor($signupId),
        );
    }

    /**
     * Forcing widens what may go, not how far back the cleanup looks. An activity with sign-ups that nobody has
     * walked away from yet is nothing to do with `--force`.
     */
    public function testForceStillOnlyReachesWhatIsAlreadyStale(): void
    {
        $draft = $this->aNeverApprovedActivityDraftWithASignup();
        $activityId = (int) $draft->getActivity()->getId();
        $this->backdate(
            ActivityRevision::class,
            (int) $draft->getId(),
            ['updatedAt' => new DateTime('-2 days')],
        );

        $this->executeCommand(['--force' => true]);

        self::assertNotNull(
            $this->entityManager->getRepository(Activity::class)->find($activityId),
        );
    }

    /**
     * A forced dry run is how an operator sees what forcing would take before they answer for it, so it has to report
     * the same removal and still change nothing.
     */
    public function testAForcedDryRunReportsTheActivityButLeavesItStanding(): void
    {
        $draft = $this->aNeverApprovedActivityDraftWithASignup();
        $activityId = (int) $draft->getActivity()->getId();

        $this->executeCommand([
            '--force' => true,
            '--dry-run' => true,
        ]);

        self::assertNotNull(
            $this->entityManager->getRepository(Activity::class)->find($activityId),
        );
    }

    /**
     * The confirmation is the only thing standing between a typed `--force` and sign-ups that cannot be brought back,
     * so declining it has to leave the run exactly where a run without the option would.
     */
    public function testDecliningTheConfirmationForcesNothing(): void
    {
        $draft = $this->aNeverApprovedActivityDraftWithASignup();
        $activityId = (int) $draft->getActivity()->getId();

        $this->executeCommand(
            ['--force' => true],
            ['no'],
            true,
        );

        self::assertNotNull(
            $this->entityManager->getRepository(Activity::class)->find($activityId),
        );
    }

    /**
     * Forcing is not a way past every domain. What a company was sold is somebody else's arrangement, and the option
     * an operator reaches for to clear out unreachable sign-ups does not touch it.
     */
    public function testForceDoesNotOverruleADomainThatRefusesOutright(): void
    {
        $company = $this->anApprovedCompanyWithAPackage();
        $live = $company->getCurrentRevision();
        self::assertInstanceOf(
            CompanyRevision::class,
            $live,
        );

        $draft = $this->cloneAsDraft($live);
        $company->setLiveRevision(null);
        $this->entityManager->flush();
        $companyId = (int) $company->getId();
        $this->backdate(
            CompanyRevision::class,
            (int) $draft->getId(),
            ['updatedAt' => new DateTime('-100 days')],
        );

        $this->executeCommand(['--force' => true]);

        self::assertNotNull(
            $this->entityManager->getRepository(Company::class)->find($companyId),
        );
    }

    /**
     * Runs non-interactively by default, as the schedule does. A case that means to answer the forced run's
     * confirmation hands in what to answer with and says so.
     *
     * @param array<string, bool|string> $input
     * @param string[]                   $answers
     */
    private function executeCommand(
        array $input = [],
        array $answers = [],
        bool $interactive = false,
    ): void {
        $this->assertCommandIsSuccessful(static::runCommand(
            'app:application:delete-stale-revisions',
            $input,
            $answers,
            $interactive,
        ));
    }

    /**
     * How many answers are still filed against a sign-up. Asked of the connection rather than the ORM, because the
     * point is what survived in the database after the run removed the rows.
     */
    private function countFieldValuesFor(int $signupId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->entityManager->getClassMetadata(SignupFieldValue::class)->getTableName()
            . ' WHERE signup_id = ?',
            [$signupId],
        );
    }

    private function fileStorage(): FileStorage
    {
        return self::getContainer()->get(FileStorage::class);
    }

    private function cloneAsDraft(RevisionInterface $source): RevisionInterface
    {
        $draft = self::getContainer()->get(RevisionClonerRegistry::class)->cloneAsDraft($source);
        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    /**
     * Write dates straight into a revision's row. Both the staleness stamp and the dates a domain judges relevance by
     * have to be set this way: `updatedAt` is stamped by a lifecycle callback, so a flush that edited the entity
     * instead would undo the ageing the test just arranged.
     *
     * A DQL update bypasses the unit of work, which leaves a revision the test already loaded holding the values it
     * was hydrated with. The run itself hydrates fresh (a cron opens on nothing), but here the same manager is shared,
     * so the row is refreshed before handing back — otherwise a domain that judges by a date would judge by the date
     * this helper just replaced.
     *
     * @param class-string            $revisionClass
     * @param array<string, DateTime> $dates
     */
    private function backdate(
        string $revisionClass,
        int $revisionId,
        array $dates,
    ): void {
        $builder = $this->entityManager->createQueryBuilder()
            ->update(
                $revisionClass,
                'r',
            )
            ->where('r.id = :id')
            ->setParameter(
                'id',
                $revisionId,
            );

        foreach ($dates as $field => $value) {
            $builder->set(
                'r.' . $field,
                ':' . $field,
            )
                ->setParameter(
                    $field,
                    $value,
                    Types::DATETIME_MUTABLE,
                );
        }

        $builder->getQuery()->execute();

        $revision = $this->entityManager->find(
            $revisionClass,
            $revisionId,
        );
        if (null === $revision) {
            return;
        }

        $this->entityManager->refresh($revision);
    }

    private function anApprovedActivityWithoutSignupLists(): Activity
    {
        $activity = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(
                Activity::class,
                'a',
            )
            ->join(
                'a.liveRevision',
                'lr',
            )
            ->where('a.currentRevision = a.liveRevision')
            ->andWhere('SIZE(lr.signupLists) = 0')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Activity::class,
            $activity,
            'The seed is expected to contain an approved activity without sign-up lists.',
        );

        return $activity;
    }

    private function aNeverApprovedActivityDraft(): ActivityRevision
    {
        $draft = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(
                ActivityRevision::class,
                'r',
            )
            ->join(
                'r.activity',
                'a',
            )
            ->where('r.status = :draft')
            ->andWhere('a.liveRevision IS NULL')
            ->andWhere('a.currentRevision = r')
            ->setParameter(
                'draft',
                RevisionStatus::Draft->value,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
            'The seed is expected to contain a never-approved draft activity.',
        );

        return $draft;
    }

    private function anApprovedVacancy(): Vacancy
    {
        $vacancy = $this->entityManager->createQueryBuilder()
            ->select('v')
            ->from(
                Vacancy::class,
                'v',
            )
            ->where('v.currentRevision = v.liveRevision')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Vacancy::class,
            $vacancy,
            'The seed is expected to contain an approved vacancy.',
        );

        return $vacancy;
    }

    private function anApprovedCompany(): Company
    {
        $company = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(
                Company::class,
                'c',
            )
            ->where('c.currentRevision = c.liveRevision')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Company::class,
            $company,
            'The seed is expected to contain an approved company.',
        );

        return $company;
    }

    private function anApprovedCompanyWithAPackage(): Company
    {
        $company = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(
                Company::class,
                'c',
            )
            ->where('c.currentRevision = c.liveRevision')
            ->andWhere('SIZE(c.packages) > 0')
            // No accounts, so the package is the only thing that can be keeping it: a company with representatives
            // would be kept by the other guard and the test would pass without proving anything.
            ->andWhere('NOT EXISTS (SELECT u FROM ' . CompanyUser::class . ' u WHERE u.company = c)')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Company::class,
            $company,
            'The seed is expected to contain an approved company that was sold a package and has no accounts.',
        );

        return $company;
    }

    private function anApprovedOrganPage(): OrganInformation
    {
        $page = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(
                OrganInformation::class,
                'o',
            )
            ->where('o.currentRevision = o.liveRevision')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            OrganInformation::class,
            $page,
            'The seed is expected to contain an approved body page.',
        );

        return $page;
    }

    private function aNeverApprovedPollRevision(): PollRevision
    {
        $revision = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(
                PollRevision::class,
                'r',
            )
            ->join(
                'r.poll',
                'p',
            )
            ->where('p.liveRevision IS NULL')
            ->andWhere('p.currentRevision = r')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            PollRevision::class,
            $revision,
            'The seed is expected to contain a poll the board never approved.',
        );

        return $revision;
    }

    /**
     * A stale, never-approved activity carrying a sign-up with an answer on it: the state a scheduled run refuses to
     * touch and a forced one clears.
     *
     * The seed has none, and could not sensibly have one — sign-ups are migrated onto a revision when it is approved,
     * so nothing in the normal course of things puts one on a chain that never was. It is built here instead, answer
     * and all, so that the removal is asked to unlink a field value from both the sign-up and the option it chose.
     */
    private function aNeverApprovedActivityDraftWithASignup(): ActivityRevision
    {
        $draft = $this->aNeverApprovedActivityDraft();

        $signupList = new SignupList();
        $signupList->setName(
            new ActivityLocalisedText(
                'Attendees',
                'Aanwezigen',
            ),
        );
        $signupList->setRevision($draft);
        $draft->addSignupList($signupList);

        $field = new SignupField();
        $field->setName(
            new ActivityLocalisedText(
                'Preference',
                'Voorkeur',
            ),
        );
        $field->setType(SignupFieldTypes::Choice);
        $field->setPosition(0);

        $option = new SignupOption();
        $option->setValue(
            new ActivityLocalisedText(
                'Either',
                'Maakt niet uit',
            ),
        );
        $option->setPosition(0);
        $field->addOption($option);
        $signupList->addField($field);

        $signup = new UserSignup();
        $signup->setSignupList($signupList);
        $signup->setUser($this->aMember());
        $signupList->getSignUps()->add($signup);

        $fieldValue = new SignupFieldValue();
        $fieldValue->setSignup($signup);
        $fieldValue->setField($field);
        $fieldValue->setOption($option);
        $signup->getFieldValues()->add($fieldValue);

        $this->entityManager->persist($signupList);
        $this->entityManager->persist($signup);
        $this->entityManager->persist($fieldValue);
        $this->entityManager->flush();

        // After the flush, so the write does not restamp what the ageing just set. The activity is put in the past as
        // well: a list is not abandoned while the evening it belongs to is still to come, forced or not.
        $this->backdate(
            ActivityRevision::class,
            (int) $draft->getId(),
            [
                'beginTime' => new DateTime('-2 months'),
                'endTime' => new DateTime('-2 months +1 hour'),
                'updatedAt' => new DateTime('-40 days'),
            ],
        );

        return $draft;
    }

    /**
     * The single sign-up {@see self::aNeverApprovedActivityDraftWithASignup()} put on the draft.
     */
    private function theSignupOn(ActivityRevision $draft): Signup
    {
        $signupList = $draft->getSignupLists()->first();
        self::assertInstanceOf(
            SignupList::class,
            $signupList,
        );

        $signup = $signupList->getSignUps()->first();
        self::assertInstanceOf(
            Signup::class,
            $signup,
        );

        return $signup;
    }

    private function aMember(): Member
    {
        $member = $this->entityManager->getRepository(Member::class)->findOneBy([]);
        self::assertInstanceOf(
            Member::class,
            $member,
            'The seed is expected to contain at least one member.',
        );

        return $member;
    }
}
