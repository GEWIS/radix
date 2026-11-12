<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Decision as DatabaseDecision;
use App\Entity\Database\Meeting as DatabaseMeeting;
use App\Entity\Database\Member as DatabaseMember;
use App\Entity\Database\SubDecision as DatabaseSubDecision;
use App\Entity\Decision\Decision as DecisionDecision;
use App\Entity\Decision\Meeting as DecisionMeeting;
use App\Entity\Decision\Member as DecisionMember;
use App\Entity\Decision\SubDecision as DecisionSubDecision;
use App\Repository\Database\DecisionRepository;
use App\Repository\Database\MeetingRepository;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Proxy;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_reverse;
use function assert;
use function count;
use function get_parent_class;
use function implode;
use function is_a;
use function preg_replace;
use function sprintf;

class MeetingService
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly MeetingRepository $meetingRepository,
        private readonly DecisionRepository $decisionRepository,
        private readonly SubDecisionService $subDecisionService,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $emWeb,
        private readonly MailerInterface $mailer,
        private readonly string $mailFromAddress,
        private readonly string $mailFromName,
        private readonly string $mailToReportErrorAddress,
        private readonly string $mailToReportErrorName,
    ) {
    }

    /**
     * Find the projection's twin of a meeting, if it has already been projected.
     */
    private function findProjectedMeeting(DatabaseMeeting $meeting): ?DecisionMeeting
    {
        return $this->emWeb->getRepository(DecisionMeeting::class)->find([
            'type' => $meeting->type,
            'number' => $meeting->getNumber(),
        ]);
    }

    /**
     * Find the projection's twin of a decision, if it has already been projected.
     */
    private function findProjectedDecision(DatabaseDecision $decision): ?DecisionDecision
    {
        return $this->emWeb->getRepository(DecisionDecision::class)->find([
            'meeting_type' => $decision->meeting->type,
            'meeting_number' => $decision->meeting->getNumber(),
            'point' => $decision->point,
            'number' => $decision->number,
        ]);
    }

    /**
     * Find the projection's twin of the decision a subdecision belongs to.
     *
     * Keyed off the subdecision's own columns rather than off its decision, so that reaching the twin does not first
     * have to load the ledger's decision.
     */
    private function findProjectedDecisionOf(DatabaseSubDecision $subdecision): ?DecisionDecision
    {
        return $this->emWeb->getRepository(DecisionDecision::class)->find([
            'meeting_type' => $subdecision->getMeetingType(),
            'meeting_number' => $subdecision->getMeetingNumber(),
            'point' => $subdecision->getDecisionPoint(),
            'number' => $subdecision->getDecisionNumber(),
        ]);
    }

    /**
     * Find the projection's twin of a subdecision, if it has already been projected.
     */
    private function findProjectedSubDecision(DatabaseSubDecision $subdecision): ?DecisionSubDecision
    {
        return $this->emWeb->getRepository(DecisionSubDecision::class)->find([
            'meeting_type' => $subdecision->getMeetingType(),
            'meeting_number' => $subdecision->getMeetingNumber(),
            'decision_point' => $subdecision->getDecisionPoint(),
            'decision_number' => $subdecision->getDecisionNumber(),
            'sequence' => $subdecision->sequence,
        ]);
    }

    /**
     * Build the projection by replaying every meeting in the order it was held.
     *
     * The projection is a materialised view of the ledger, and the ledger is append-only: the state it describes is
     * whatever you end up with after going through the decisions in order. So that is all this does. Every
     * subdecision is applied the moment it is reached, including the annulments, which revert what their target set
     * in motion at exactly the point in the ledger where that happened. Entities being created and later removed
     * again along the way is expected, and is what makes the result the same whether the projection was empty or
     * already up to date.
     *
     * Progress is reported through the callback rather than written to the console here, so that the service stays
     * usable outside of a command.
     *
     * @param (Closure(int $current, int $total): void)|null $onProgress
     */
    public function generate(?Closure $onProgress = null): void
    {
        // every meeting, oldest first
        $meetings = $this->meetingRepository->findAllWithDecisionCount(true);
        $total = count($meetings);

        $num = 0;
        foreach ($meetings as $meeting) {
            $this->generateMeeting($meeting[0]);
            $this->emWeb->flush();
            // Nothing generated so far is needed again by name, and keeping all of it makes every subsequent flush
            // more expensive than the last.
            $this->emWeb->clear();
            ++$num;

            if (null === $onProgress) {
                continue;
            }

            $onProgress($num, $total);
        }

        $this->linkCounterparts();

        $this->emWeb->flush();
    }

    /**
     * Link every projected decision that repeats another to the one it repeats.
     *
     * A pass of its own rather than part of projecting a decision, because the meetings are replayed oldest first and a
     * virtual meeting may sort before the meeting whose decision it repeats (a meeting's date can be corrected after
     * the fact). Projecting them in one go and matching up afterwards is what makes the link independent of that order.
     */
    private function linkCounterparts(): void
    {
        foreach ($this->decisionRepository->findRepeats() as $decision) {
            $decisionDecision = $this->findProjectedDecision($decision);
            $counterpart = $decision->counterpart;

            if (
                null === $decisionDecision
                || null === $counterpart
            ) {
                continue;
            }

            $decisionDecision->counterpart = $this->findProjectedDecision($counterpart);
        }
    }

    public function generateMeeting(DatabaseMeeting $meeting): void
    {
        $decisionMeeting = $this->findProjectedMeeting($meeting);

        if (null === $decisionMeeting) {
            $decisionMeeting = new DecisionMeeting();
            $decisionMeeting->type = $meeting->type;
            $decisionMeeting->number = $meeting->getNumber();
            $decisionMeeting->date = $meeting->date;
        } elseif ($decisionMeeting->date->format('Y-m-d') !== $meeting->date->format('Y-m-d')) {
            // The type and number identify the meeting and can therefore never change, but the date can be corrected
            // after the fact, so it must be kept in sync. Only assign it when the stored date actually differs:
            // Doctrine compares objects by identity, so assigning an equal but distinct DateTimeImmutable marks the
            // meeting as changed and rewrites the row on every projection.
            $decisionMeeting->date = $meeting->date;
        }

        foreach ($meeting->getDecisions() as $decision) {
            try {
                $this->generateDecision(
                    $decision,
                    $decisionMeeting,
                );
            } catch (Throwable $e) {
                // send email, something went wrong
                $this->sendDecisionExceptionMail(
                    $e,
                    $decision,
                );
                continue;
            }
        }

        $this->emWeb->persist($decisionMeeting);
    }

    public function generateDecision(
        DatabaseDecision $decision,
        ?DecisionMeeting $decisionMeeting = null,
    ): void {
        if (null === $decisionMeeting) {
            $decisionMeeting = $this->findProjectedMeeting($decision->meeting);

            if (null === $decisionMeeting) {
                throw new LogicException('Decision without meeting');
            }
        }

        // see if decision exists
        $decisionDecision = $this->findProjectedDecision($decision);

        if (null === $decisionDecision) {
            $decisionDecision = new DecisionDecision();
            $decisionDecision->setMeeting($decisionMeeting);
            $decisionDecision->point = $decision->point;
            $decisionDecision->number = $decision->number;
        }

        // The decision a virtual one repeats, as far as it can be determined here: a decision the projection does not
        // know yet is left for {@see self::linkCounterparts()}, which runs once every meeting has been replayed.
        $counterpart = $decision->counterpart;
        $projectedCounterpart = null === $counterpart
            ? null
            : $this->findProjectedDecision($counterpart);

        if (
            null === $counterpart
            || null !== $projectedCounterpart
        ) {
            $decisionDecision->counterpart = $projectedCounterpart;
        }

        $contentNL = [];
        $contentEN = [];

        foreach ($decision->getSubdecisions() as $subdecision) {
            $decisionSubDecision = $this->generateSubDecision(
                $subdecision,
                $decisionDecision,
            );
            // Applied right here, so that what a subdecision brings about is in place before the next one is read.
            $this->subDecisionService->generateRelated($decisionSubDecision);
            $contentNL[] = $subdecision->getTranslatedContent(
                $this->translator,
                AppLanguages::Dutch,
            );
            $contentEN[] = $subdecision->getTranslatedContent(
                $this->translator,
                AppLanguages::English,
            );
        }

        if (empty($contentNL)) {
            $contentNL[] = '';
            $contentEN[] = '';
        }

        $decisionDecision->contentNL = implode(
            ' ',
            $contentNL,
        );
        $decisionDecision->contentEN = implode(
            ' ',
            $contentEN,
        );

        $this->emWeb->persist($decisionDecision);
    }

    /**
     * A subdecision written after its decision was recorded leaves the decision itself with the text it had before, and
     * that text is what the site shows and what the search reads. A decision the projection does not know yet is left
     * to {@see self::generateDecision()}, which is about to write it in full.
     */
    public function refreshDecisionContent(DatabaseDecision $decision): void
    {
        $decisionDecision = $this->findProjectedDecision($decision);

        if (null === $decisionDecision) {
            return;
        }

        $contentNL = [];
        $contentEN = [];

        foreach ($decision->getSubdecisions() as $subdecision) {
            $contentNL[] = $subdecision->getTranslatedContent(
                $this->translator,
                AppLanguages::Dutch,
            );
            $contentEN[] = $subdecision->getTranslatedContent(
                $this->translator,
                AppLanguages::English,
            );
        }

        $decisionDecision->contentNL = implode(
            ' ',
            $contentNL,
        );
        $decisionDecision->contentEN = implode(
            ' ',
            $contentEN,
        );

        $this->emWeb->persist($decisionDecision);
    }

    /**
     * Project a subdecision onto its `App\Entity\Decision` counterpart.
     *
     * The two class trees mirror each other one-for-one, so which report class belongs to a subdecision follows from
     * that subdecision's own class name.
     */
    public function generateSubDecision(
        DatabaseSubDecision $subdecision,
        ?DecisionDecision $decisionDecision = null,
    ): DecisionSubDecision {
        if (null === $decisionDecision) {
            $decisionDecision = $this->findProjectedDecisionOf($subdecision);

            if (null === $decisionDecision) {
                throw new LogicException('Decision without meeting');
            }
        }

        // The projection's subdecision is the same class one namespace over, so the class to build is the ledger's
        // with `Database` swapped for `Decision`. A subdecision whose name does not rewrite would silently build a
        // ledger entity here, which is a bug rather than a case to handle.
        /** @var class-string<DecisionSubDecision> $class */
        $class = preg_replace(
            '/^App\\\\Entity\\\\Database\\\\/',
            'App\\\\Entity\\\\Decision\\\\',
            $this->realClass($subdecision),
        );

        if (
            !is_a(
                $class,
                DecisionSubDecision::class,
                true,
            )
        ) {
            throw new LogicException(sprintf('No projection exists for %s', $subdecision::class));
        }

        $decisionSubDecision = $this->findProjectedSubDecision($subdecision);

        if (
            null !== $decisionSubDecision
            && $this->realClass($decisionSubDecision) !== $class
        ) {
            // A subdecision that turned out to be wrong is put right by replacing it with one of another kind, and then
            // this position no longer matches what the projection has at it. Nothing about the old one is still true,
            // so it goes, along with everything derived from it, rather than being converted into the new one; keeping
            // it would leave the body member it discharged, or the body it founded, based on a decision that was never
            // taken. Its removal has to reach the database before the replacement takes its place, because they share
            // an identity and a single flush would insert before it deletes.
            $this->deleteSubDecision($decisionSubDecision);
            $this->emWeb->flush();

            $decisionSubDecision = null;
        }

        if (null === $decisionSubDecision) {
            $decisionSubDecision = new $class();
            $decisionSubDecision->setDecision($decisionDecision);
            $decisionSubDecision->sequence = $subdecision->sequence;
        }

        if ($subdecision instanceof DatabaseSubDecision\FoundationReference) {
            // The report subdecision is the same class in the report namespace, built by rewriting the
            // namespace above; asserting it here is what lets the branch use that class's setters.
            assert($decisionSubDecision instanceof DecisionSubDecision\FoundationReference);

            $ref = $subdecision->foundation;
            $foundation = $this->findProjectedSubDecision($ref);
            assert($foundation instanceof DecisionSubDecision\Foundation);

            $decisionSubDecision->foundation = $foundation;
        }

        // transfer specific data
        if ($subdecision instanceof DatabaseSubDecision\Installation) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Installation);

            $decisionSubDecision->function = $subdecision->function;
            $decisionSubDecision->setMember($this->findMember($subdecision->getMember()));
        } elseif (
            $subdecision instanceof DatabaseSubDecision\Reappointment
            || $subdecision instanceof DatabaseSubDecision\Discharge
        ) {
            assert(
                $decisionSubDecision instanceof DecisionSubDecision\Reappointment
                || $decisionSubDecision instanceof DecisionSubDecision\Discharge,
            );

            $ref = $subdecision->installation;
            $installation = $this->findProjectedSubDecision($ref);
            assert($installation instanceof DecisionSubDecision\Installation);

            $decisionSubDecision->installation = $installation;
        } elseif ($subdecision instanceof DatabaseSubDecision\Foundation) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Foundation);

            $decisionSubDecision->name = $subdecision->name;
            $decisionSubDecision->abbr = $subdecision->abbr;
            $decisionSubDecision->purpose = $subdecision->getPurpose();
            $decisionSubDecision->organType = $subdecision->organType;
        } elseif (
            $subdecision instanceof DatabaseSubDecision\Financial\Statement
            || $subdecision instanceof DatabaseSubDecision\Financial\Budget
            || $subdecision instanceof DatabaseSubDecision\OrganRegulation
        ) {
            // There are 147 Board Meetings before BV 1209 that have an "unknown" author for a budget and/or financial
            // statement. As such, we need to allow for the member to be null here. In that case, we simply will not set
            // a member for the report subdecision, and it will be shown as "unknown" in the (sub)decision content.
            assert(
                $decisionSubDecision instanceof DecisionSubDecision\Financial\Budget
                || $decisionSubDecision instanceof DecisionSubDecision\OrganRegulation,
            );

            if (null !== $subdecision->getMember()) {
                $decisionSubDecision->setMember($this->findMember($subdecision->getMember()));
            }

            // Specific to the `OrganRegulation`s, set the abbr and type of organ
            if ($subdecision instanceof DatabaseSubDecision\OrganRegulation) {
                assert($decisionSubDecision instanceof DecisionSubDecision\OrganRegulation);

                $decisionSubDecision->abbr = $subdecision->abbr;
                $decisionSubDecision->setOrganType($subdecision->organType);
            } else {
                assert($decisionSubDecision instanceof DecisionSubDecision\Financial\Budget);

                $decisionSubDecision->name = $subdecision->name;
            }

            $decisionSubDecision->version = $subdecision->version;
            $decisionSubDecision->date = $subdecision->date;
            $decisionSubDecision->approval = $subdecision->approval;
            $decisionSubDecision->changes = $subdecision->changes;
        } elseif ($subdecision instanceof DatabaseSubDecision\Minutes) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Minutes);

            $meeting = $this->findProjectedMeeting($subdecision->getTarget());
            assert($meeting instanceof DecisionMeeting);

            $decisionSubDecision->meeting = $meeting;
            $decisionSubDecision->setMember($this->findMember($subdecision->getMember()));
            $decisionSubDecision->approval = $subdecision->approval;
            $decisionSubDecision->changes = $subdecision->changes;
        } elseif ($subdecision instanceof DatabaseSubDecision\Board\Installation) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Board\Installation);

            $decisionSubDecision->function = $subdecision->function;
            $decisionSubDecision->setMember($this->findMember($subdecision->getMember()));
            $decisionSubDecision->date = $subdecision->date;
        } elseif ($subdecision instanceof DatabaseSubDecision\Board\Release) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Board\Release);

            $ref = $subdecision->installation;
            $installation = $this->findProjectedSubDecision($ref);
            assert($installation instanceof DecisionSubDecision\Board\Installation);

            $decisionSubDecision->installation = $installation;
            $decisionSubDecision->date = $subdecision->date;
        } elseif ($subdecision instanceof DatabaseSubDecision\Board\Discharge) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Board\Discharge);

            $ref = $subdecision->installation;
            $installation = $this->findProjectedSubDecision($ref);
            assert($installation instanceof DecisionSubDecision\Board\Installation);

            $decisionSubDecision->installation = $installation;
        } elseif ($subdecision instanceof DatabaseSubDecision\Board\Candidacy) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Board\Candidacy);

            $decisionSubDecision->boardYear = $subdecision->boardYear;
        } elseif ($subdecision instanceof DatabaseSubDecision\Board\Candidate) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Board\Candidate);

            $decisionSubDecision->setMember($this->findMember($subdecision->getMember()));
        } elseif ($subdecision instanceof DatabaseSubDecision\Key\Granting) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Key\Granting);

            $decisionSubDecision->setMember($this->findMember($subdecision->getMember()));
            $decisionSubDecision->until = $subdecision->until;
        } elseif ($subdecision instanceof DatabaseSubDecision\Key\Withdrawal) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Key\Withdrawal);

            $ref = $subdecision->granting;
            $granting = $this->findProjectedSubDecision($ref);
            assert($granting instanceof DecisionSubDecision\Key\Granting);

            $decisionSubDecision->granting = $granting;
            $decisionSubDecision->withdrawnOn = $subdecision->withdrawnOn;
        } elseif ($subdecision instanceof DatabaseSubDecision\Member\Warning) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Member\Warning);

            $decisionSubDecision->setMember($this->findMember($subdecision->getMember()));
        } elseif ($subdecision instanceof DatabaseSubDecision\Member\Suspension) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Member\Suspension);

            $decisionSubDecision->setMember($this->findMember($subdecision->getMember()));
            $decisionSubDecision->since = $subdecision->since;
            $decisionSubDecision->until = $subdecision->until;
        } elseif ($subdecision instanceof DatabaseSubDecision\Annulment) {
            assert($decisionSubDecision instanceof DecisionSubDecision\Annulment);

            $target = $this->findProjectedDecision($subdecision->target);
            assert($target instanceof DecisionDecision);

            $decisionSubDecision->target = $target;

            // Annulment must be handled here, because it cannot be part of the process{X}Updates because the
            // subdecision is the annulment, not the target subdecision(s).
            $this->annulDecision($target);
        }

        // Abolish decisions are handled by foundationreference
        // Other decisions don't need special handling

        // for any decision, make sure the content is filled for Dutch and English
        $decisionSubDecision->contentNL = $subdecision->getTranslatedContent(
            $this->translator,
            AppLanguages::Dutch,
        );
        $decisionSubDecision->contentEN = $subdecision->getTranslatedContent(
            $this->translator,
            AppLanguages::English,
        );
        $this->emWeb->persist($decisionSubDecision);

        return $decisionSubDecision;
    }

    /**
     * Annuls a previously recorded decision and its subdecisions in the projection.
     *
     * This function reverts the effects of a target decision by undoing or removing the entities that were derived
     * from its subdecisions. Each subdecision type is handled explicitly by {@see SubDecisionService::revertRelated()}
     * to ensure that the data remains consistent and auditable.
     *
     * The register operates as a ledger, meaning the chronological order of decisions must be preserved. A target
     * decision made at point X may be annulled at point Z, but only while no decision in between builds on it. That
     * rule is enforced in the `Database` domain, which owns the ledger and rejects an annulment that would break it; by
     * the time an annulment reaches the projection it merely has to be applied.
     *
     * NOTE: to adhere to our ordering assumption within a decision, we must loop through its subdecisions in reverse.
     */
    private function annulDecision(DecisionDecision $target): void
    {
        foreach (array_reverse($target->getSubdecisions()->toArray()) as $targetSubDecision) {
            $this->subDecisionService->revertRelated($targetSubDecision);
        }
    }

    /**
     * Undoes an annulment, restoring the entities that were derived from the annulled decision.
     *
     * The same ledger assumption applies as for {@see self::annulDecision()}: the annulment can only be undone while
     * nothing has been decided about the affected entities since.
     */
    private function unannulDecision(DecisionDecision $target): void
    {
        foreach ($target->getSubdecisions() as $targetSubDecision) {
            $this->subDecisionService->generateRelated($targetSubDecision);
        }
    }

    public function deleteDecision(DatabaseDecision $decision): void
    {
        $decisionDecision = $this->findProjectedDecision($decision);

        foreach (array_reverse($decisionDecision->getSubdecisions()->toArray()) as $subDecision) {
            $this->deleteSubDecision($subDecision);
        }

        $this->emWeb->remove($decisionDecision);
    }

    public function deleteSubDecision(DecisionSubDecision $subDecision): void
    {
        if ($subDecision instanceof DecisionSubDecision\Annulment) {
            if ($this->subDecisionService->stillReferences($subDecision)) {
                $this->unannulDecision($subDecision->target);
            }
        } else {
            // Deleting a subdecision undoes its effects in exactly the same way that annulling it does.
            $this->subDecisionService->revertRelated($subDecision);

            // On top of that, the subdecision is about to disappear, so the references to it must be dropped as well.
            // One that no longer has the subdecision it points at has nothing to drop; it only needs the revert above.
            if ($this->subDecisionService->stillReferences($subDecision)) {
                switch (true) {
                    case $subDecision instanceof DecisionSubDecision\Discharge:
                        $subDecision->installation->clearDischarge();
                        break;

                    case $subDecision instanceof DecisionSubDecision\Board\Release:
                        $subDecision->installation->clearRelease();
                        break;

                    case $subDecision instanceof DecisionSubDecision\Board\Discharge:
                        $subDecision->installation->clearDischarge();
                        break;

                    case $subDecision instanceof DecisionSubDecision\Key\Withdrawal:
                        $subDecision->granting->clearWithdrawal();
                        break;
                }
            }

            // A body keeps a list of the decisions it was shaped by, and that list prevents the row from being deleted
            // until the body no longer references it.
            $this->subDecisionService->detachFromOrgans($subDecision);
        }

        $this->emWeb->remove($subDecision);
    }

    /**
     * The class an entity actually is, rather than the one it presents itself as.
     *
     * Doctrine creates proxies for entities it has not loaded yet, and those are subclasses in a namespace of their
     * own. Anything reasoning about which kind of subdecision it has must look past that.
     *
     * @return class-string
     */
    private function realClass(object $entity): string
    {
        if ($entity instanceof Proxy) {
            /** @var class-string $parent */
            $parent = get_parent_class($entity);

            return $parent;
        }

        return $entity::class;
    }

    /**
     * Obtain the correct member, given a database member. Because these members are generated based on what happens in
     * the `Member` domain, this cannot return `null`.
     */
    public function findMember(DatabaseMember $member): DecisionMember
    {
        $decisionMember = $this->emWeb->getRepository(DecisionMember::class)
            ->find($member->lidnr);

        if (null === $decisionMember) {
            throw new LogicException(
                sprintf(
                    'Member %d does not exist in the projection',
                    $member->lidnr,
                ),
            );
        }

        return $decisionMember;
    }

    /**
     * Send an email about that something went wrong.
     */
    public function sendDecisionExceptionMail(
        Throwable $e,
        DatabaseDecision $decision,
    ): void {
        $meeting = $decision->meeting;
        $body = <<<BODYTEXT
            Hallo Belangrijke Database Mensen,

            Ik ben een fout tegen gekomen tijdens het processen:

            {$e->getMessage()}

            Dit gebeurde tijdens het processen van onderstaand besluit:
            {$meeting->type->value} {$meeting->getNumber()}.{$decision->point}.{$decision->number}.

            Met vriendelijke groet,

            De GEWIS Database

            PS: extra info over de fout:

            {$e->getTraceAsString()}
            BODYTEXT;

        $email = new Email()
            ->from(new Address($this->mailFromAddress, $this->mailFromName))
            ->to(new Address($this->mailToReportErrorAddress, $this->mailToReportErrorName))
            ->subject('Database fout')
            ->text($body);

        $this->mailer->send($email);
    }
}
