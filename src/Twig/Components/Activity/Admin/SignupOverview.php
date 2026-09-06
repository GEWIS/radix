<?php

declare(strict_types=1);

namespace App\Twig\Components\Activity\Admin;

use App\Attribute\Application\ReadOnlySafe;
use App\Entity\Activity\Activity;
use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\RecipientScope;
use App\Entity\Activity\Enums\SignupFilter;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\Languages;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Message\Activity\OrganiserAnnouncementEmail;
use App\Security\Application\RevisionVoter;
use App\Security\User\SudoVoter;
use App\Service\Activity\DrawManager;
use App\Util\Activity\SignupAdminWindow;
use App\ViewModel\Activity\Admin\SignupAdminListView;
use App\ViewModel\Activity\Admin\SignupPeopleView;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

use function array_key_exists;
use function array_map;
use function assert;
use function count;
use function in_array;
use function sprintf;
use function trim;

/**
 * The sign-ups of one activity, read one list at a time or everybody side by side: every subscriber with their
 * answers, membership type and attendance, a per-row present and admitted toggle, row selection, a search and quick
 * filters, and a bulk email composer.
 *
 * Unlike the other (read-only) live components this one writes to the database (attendance marking) and dispatches
 * messages (bulk email), so it re-asserts access on every action: a live request is independent of the gated page that
 * embedded the component, and the activity prop is rehydrated from a client-supplied id.
 *
 * Feedback ($feedback/$feedbackType) is component-local rather than a session flash: a live action re-renders only this
 * component, not the layout that shows flashes, so the message is rendered inside the component and is transient (reset
 * on the next interaction).
 */
#[AsLiveComponent(
    name: 'Activity:Admin:SignupOverview',
    template: 'components/Activity/Admin/SignupOverview.html.twig',
)]
#[IsGranted(new Expression("is_granted('ROLE_ACTIVE_MEMBER') or is_granted('ROLE_BOARD')"))]
#[IsGranted(SudoVoter::ATTRIBUTE)]
final class SignupOverview
{
    use DefaultActionTrait;

    #[LiveProp]
    public Activity $activity;

    /**
     * The ticked signup ids, held as one flat set. Signup ids are globally unique, so this maps back to lists
     * unambiguously and every operation on it (the per-list "Selected" count, the email recipients, selectAll() and
     * clearSelection()) is scoped to a single sign-up list; there is no cross-list selection. Checkbox hydration
     * delivers the ids as strings while selectAll() pushes ints, so the type is mixed; read them normalised via
     * {@see self::selectedIds()}.
     *
     * @var list<int|string>
     */
    #[LiveProp(writable: true)]
    public array $selected = [];

    /**
     * Ids of the sign-up fields whose column the organiser has hidden, as one flat set. Field ids are globally
     * unique, so, like {@see self::$selected}, this is effectively per-list (a hidden id only matches the one
     * list it belongs to). Toggled via {@see self::toggleFieldColumn()}; read normalised via
     * {@see self::hiddenFieldIds()}.
     *
     * @var list<int|string>
     */
    #[LiveProp(writable: true)]
    public array $hiddenFields = [];

    #[LiveProp(writable: true)]
    public string $filter = '';

    // "list": one sign-up list at a time; "people": everybody on the activity, one row per person and a column per
    // list, which is the reading that shows who is in two lists and who is stuck on a waiting list.
    #[LiveProp(
        writable: true,
        url: true,
    )]
    public string $mode = self::MODE_LIST;

    // The list on screen in list mode (null = the first).
    #[LiveProp(
        writable: true,
        url: true,
    )]
    public ?int $activeListId = null;

    // The backing value of a SignupFilter; held as a string so it survives hydration whatever the enum does.
    #[LiveProp(
        writable: true,
        url: true,
    )]
    public string $quickFilter = SignupFilter::All->value;

    /**
     * The optional columns the organiser hid: "generation", "signedUpAt" or "otherLists". Pure display state, like
     * {@see self::$hiddenFields}.
     *
     * @var list<string>
     */
    #[LiveProp(writable: true)]
    public array $hiddenColumns = [];

    // Whether the email composer is open. It composes for whatever is on screen: the list in list mode, everybody in
    // people mode, so the recipients and the selection stay coherent.
    #[LiveProp(writable: true)]
    public bool $composerOpen = false;

    // Held as the backing string and converted to a RecipientScope; avoids relying on enum-prop hydration.
    #[LiveProp(writable: true)]
    public string $scope = RecipientScope::All->value;

    #[LiveProp(writable: true)]
    public string $emailSubject = '';

    #[LiveProp(writable: true)]
    public string $emailBody = '';

    // Which sign-up list is in attendance mode (null = none). Attendance mode replaces the table with a focused,
    // mobile-first search list of large tap targets, because presence is marked on a phone at the door.
    #[LiveProp(writable: true)]
    public ?int $attendanceListId = null;

    // Transient, set by an action and rendered once in this component's own markup.
    public ?string $feedback = null;
    public string $feedbackType = '';

    public const string MODE_LIST = 'list';
    public const string MODE_PEOPLE = 'people';

    /** @var SignupAdminListView[]|null memoised for the duration of one render */
    private ?array $listViews = null;

    private ?SignupPeopleView $peopleView = null;

    /** @var array<string, list<array{listId: int, name: string, waiting: bool}>>|null every list each person is in */
    private ?array $memberships = null;

    public function __construct(
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly string $internalAffairsEmail,
        private readonly DrawManager $drawManager,
    ) {
    }

    /**
     * @return SignupAdminListView[]
     */
    public function getListViews(): array
    {
        $this->assertAccess();

        if (null !== $this->listViews) {
            return $this->listViews;
        }

        $this->primeSignups();

        $views = [];
        $selected = $this->selectedIds();
        $hiddenFields = $this->hiddenFieldIds();
        $memberships = $this->memberships();
        foreach ($this->activity->getLiveSignupLists() as $signupList) {
            $views[] = SignupAdminListView::fromSignupList(
                $signupList,
                $this->translator,
                $this->filter,
                $selected,
                $hiddenFields,
                $this->isPeopleMode() ? SignupFilter::All : $this->quickFilterValue(),
                $memberships,
            );
        }

        return $this->listViews = $views;
    }

    /**
     * The list on screen: the one the organiser picked, or the first when there is none or it is gone.
     */
    public function getActiveList(): ?SignupAdminListView
    {
        $views = $this->getListViews();

        foreach ($views as $view) {
            if ($view->listId === $this->activeListId) {
                return $view;
            }
        }

        return $views[0] ?? null;
    }

    public function getPeopleView(): SignupPeopleView
    {
        $this->assertAccess();

        if (null !== $this->peopleView) {
            return $this->peopleView;
        }

        $this->primeSignups();

        return $this->peopleView = SignupPeopleView::fromLists(
            $this->activity->getLiveSignupLists()->getValues(),
            $this->translator,
            $this->filter,
            $this->selectedIds(),
            $this->hiddenFieldIds(),
            $this->quickFilterValue(),
        );
    }

    public function isPeopleMode(): bool
    {
        return self::MODE_PEOPLE === $this->mode;
    }

    public function quickFilterValue(): SignupFilter
    {
        return SignupFilter::tryFrom($this->quickFilter) ?? SignupFilter::All;
    }

    /**
     * The quick filters on offer for what is on screen.
     *
     * @return list<SignupFilter>
     */
    public function getFilters(): array
    {
        if ($this->isPeopleMode()) {
            return SignupFilter::forPeople();
        }

        return SignupFilter::forList((bool) $this->getActiveList()?->limitedCapacity);
    }

    public function columnShown(string $column): bool
    {
        return !in_array(
            $column,
            $this->hiddenColumns,
            true,
        );
    }

    /**
     * Every list of the activity each person is in, so a row can say where else they are.
     *
     * @return array<string, list<array{listId: int, name: string, waiting: bool}>>
     */
    private function memberships(): array
    {
        if (null !== $this->memberships) {
            return $this->memberships;
        }

        $language = Languages::current();
        $memberships = [];
        foreach ($this->activity->getLiveSignupLists() as $signupList) {
            foreach ($this->confirmedSignups($signupList) as $signup) {
                $memberships[$signup->personKey()][] = [
                    'listId' => $signupList->getId() ?? 0,
                    'name' => $signupList->getName()->getText($language) ?? '',
                    'waiting' => $signupList->getLimitedCapacity() && !$signup->isDrawn(),
                ];
            }
        }

        return $this->memberships = $memberships;
    }

    /**
     * Eagerly load the sign-ups and the associations each row reads for this activity's live lists in a fixed number
     * of queries: the member behind a user sign-up (its type and generation columns are fetched at the same time) and
     * every answer's field and option. Without this, building the table lazy-loads the member and the field values
     * per row: an N+1 that grows with the number of sign-ups.
     */
    private function primeSignups(): void
    {
        $lists = $this->activity->getLiveSignupLists()->toArray();
        if ([] === $lists) {
            return;
        }

        // Each list's sign-ups in one query, instead of one lazy load per list.
        $this->entityManager
            ->createQuery(
                'SELECT sl, s FROM ' . SignupList::class . ' sl LEFT JOIN sl.signUps s WHERE sl IN (:lists)',
            )
            ->setParameter(
                'lists',
                $lists,
            )
            ->getResult();

        // The member behind every user sign-up in one query.
        $this->entityManager
            ->createQuery(
                'SELECT us, u FROM ' . UserSignup::class . ' us JOIN us.user u WHERE us.signupList IN (:lists)',
            )
            ->setParameter(
                'lists',
                $lists,
            )
            ->getResult();

        // Every answer with its field and option, and the sign-up's own role, in one query, so neither
        // displayValueForField() nor the role column lazy-loads per row.
        $this->entityManager
            ->createQuery(
                'SELECT s, fv, f, o, r FROM ' . Signup::class . ' s'
                . ' LEFT JOIN s.fieldValues fv LEFT JOIN fv.field f LEFT JOIN fv.option o LEFT JOIN s.role r'
                . ' WHERE s.signupList IN (:lists)',
            )
            ->setParameter(
                'lists',
                $lists,
            )
            ->getResult();

        // The lists' roles, or the role picker is one lazy load per list.
        $this->entityManager
            ->createQuery(
                'SELECT sl, r FROM ' . SignupList::class . ' sl LEFT JOIN sl.roles r WHERE sl IN (:lists)',
            )
            ->setParameter(
                'lists',
                $lists,
            )
            ->getResult();
    }

    /**
     * Whether attendance may be marked right now (30 minutes before the activity until a day after it ends).
     */
    public function canMarkPresence(): bool
    {
        return SignupAdminWindow::canMarkPresence(
            $this->activity->getBeginTime(),
            $this->activity->getEndTime(),
        );
    }

    /**
     * Whether the current user is on the board (the only role allowed to perform a draw).
     */
    public function isBoard(): bool
    {
        return $this->security->isGranted(UserRoles::Board->value);
    }

    /**
     * Whether admission (the draw and manual admit/un-admit) may still be changed. Open until a day after the activity
     * ends (the same upper bound as attendance), so a draw forgotten before the activity can still be run at the door
     * (otherwise a never-drawn limited list would end up with no admission and, since presence needs admission, no
     * attendance either).
     */
    public function admissionOpen(): bool
    {
        return SignupAdminWindow::canChangeAdmission($this->activity->getEndTime());
    }

    /**
     * The recipient groups offered in the composer for what is on screen, each with how many people it reaches and
     * what it means. {@see RecipientScope::Admitted}/{@see RecipientScope::Waitlisted} only distinguish anybody on a
     * limited-capacity list, where a draw sets admittees apart from the waiting list.
     *
     * @return list<array{scope: RecipientScope, count: int, explain: string}>
     */
    public function getAudiences(): array
    {
        $selected = [] !== $this->selectedIds();
        $pick = $this->translator->trans('Tick rows in the table to mail a hand-picked group.');
        $ticked = $this->translator->trans('Just the rows you ticked.');

        if ($this->isPeopleMode()) {
            $scopes = [
                [
                    RecipientScope::All,
                    $this->translator->trans('Everybody on this activity, whichever list they are in.'),
                ],
                [
                    RecipientScope::Multi,
                    $this->translator->trans(
                        'People in more than one part of the activity: the group that needs a combined timetable.',
                    ),
                ],
                [
                    RecipientScope::Waitlisted,
                    $this->translator->trans('Everybody still waiting for a place on at least one list.'),
                ],
                [
                    RecipientScope::External,
                    $this->translator->trans('Externals and non-members involved in this activity.'),
                ],
                [
                    RecipientScope::Present,
                    $this->translator->trans('Everybody marked present on any list.'),
                ],
                [
                    RecipientScope::Selected,
                    $selected ? $ticked : $pick,
                ],
            ];
        } else {
            $list = $this->getActiveList();
            $name = null === $list
                ? ''
                : $list->name;
            $scopes = [
                [
                    RecipientScope::All,
                    $this->translator->trans(
                        'Everybody on %list%, the waiting list included.',
                        ['%list%' => $name],
                    ),
                ],
            ];

            if (
                null !== $list
                && $list->limitedCapacity
                && (
                    $list->drawLocked
                    || $list->allocationMethod->isManual()
                )
            ) {
                $scopes[] = [
                    RecipientScope::Admitted,
                    $this->translator->trans(
                        'Only the people with a place on %list%.',
                        ['%list%' => $name],
                    ),
                ];
                $scopes[] = [
                    RecipientScope::Waitlisted,
                    $this->translator->trans(
                        'Only the people still waiting for a place on %list%.',
                        ['%list%' => $name],
                    ),
                ];
            }

            $scopes[] = [
                RecipientScope::External,
                $this->translator->trans(
                    'Externals and non-members on %list%, for the practical details members already know.',
                    ['%list%' => $name],
                ),
            ];
            $scopes[] = [
                RecipientScope::Present,
                $this->translator->trans(
                    'The people marked present on %list%.',
                    ['%list%' => $name],
                ),
            ];
            $scopes[] = [
                RecipientScope::Selected,
                $selected ? $ticked : $pick,
            ];
        }

        $audiences = [];
        foreach ($scopes as [$scope, $explain]) {
            $audiences[] = [
                'scope' => $scope,
                'count' => count($this->recipients($scope)),
                'explain' => $explain,
            ];
        }

        return $audiences;
    }

    public function scopeValue(): RecipientScope
    {
        return RecipientScope::tryFrom($this->scope) ?? RecipientScope::All;
    }

    /**
     * Who the message on screen would go to.
     *
     * @return list<array{email: string, name: string, external: bool}>
     */
    public function getRecipients(): array
    {
        return $this->recipients($this->scopeValue());
    }

    public function getExternalRecipientCount(): int
    {
        $count = 0;
        foreach ($this->getRecipients() as $recipient) {
            if (!$recipient['external']) {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    /**
     * Where a reply lands: the organising body's public address, or Internal Affairs when it has none.
     */
    private function replyTo(): string
    {
        $organEmail = $this->activity->getOrgan()?->getOrganInformation()?->getEmail();

        return null !== $organEmail && '' !== $organEmail
            ? $organEmail
            : $this->internalAffairsEmail;
    }

    /**
     * @return list<array{email: string, name: string, external: bool}>
     */
    private function recipients(RecipientScope $scope): array
    {
        if ($this->isPeopleMode()) {
            return $this->recipientsAcrossLists($scope);
        }

        $list = $this->getActiveList();
        if (null === $list) {
            return [];
        }

        $signupList = $this->findOwnedList($list->listId);
        if (null === $signupList) {
            return [];
        }

        return $this->recipientsFor(
            $signupList,
            $scope,
        );
    }

    /**
     * The recipients of a message to everybody on the activity: one address per person, whatever the number of
     * lists they are in, since the same practical mail twice reads as a mistake.
     *
     * @return list<array{email: string, name: string, external: bool}>
     */
    private function recipientsAcrossLists(RecipientScope $scope): array
    {
        $selected = $this->selectedIds();
        $recipients = [];
        $seen = [];
        foreach ($this->activity->getLiveSignupLists() as $signupList) {
            foreach ($this->confirmedSignups($signupList) as $signup) {
                $key = $signup->personKey();
                $memberships = $this->memberships()[$key] ?? [];
                $external = !$signup instanceof UserSignup;
                $include = match ($scope) {
                    RecipientScope::All => true,
                    RecipientScope::Multi => count($memberships) > 1,
                    RecipientScope::Waitlisted => $this->waitingSomewhere($memberships),
                    RecipientScope::External => $external,
                    RecipientScope::Present => $signup->isPresent(),
                    RecipientScope::Selected => in_array(
                        $signup->getId(),
                        $selected,
                        true,
                    ),
                    RecipientScope::Admitted => $signup->isDrawn(),
                };

                if (
                    !$include
                    || array_key_exists(
                        $key,
                        $seen,
                    )
                ) {
                    continue;
                }

                $email = $signup->getEmail();
                if (null === $email) {
                    continue;
                }

                $seen[$key] = true;
                $recipients[] = [
                    'email' => $email,
                    'name' => $signup->getFullName(),
                    'external' => $external,
                ];
            }
        }

        return $recipients;
    }

    /**
     * @param list<array{listId: int, name: string, waiting: bool}> $memberships
     */
    private function waitingSomewhere(array $memberships): bool
    {
        foreach ($memberships as $membership) {
            if ($membership['waiting']) {
                return true;
            }
        }

        return false;
    }

    #[LiveAction]
    public function togglePresent(#[LiveArg]
    int $signupId,): void
    {
        $this->assertAccess();

        $signup = $this->findOwnedSignup($signupId);
        if (
            null === $signup
            || !$this->canMarkPresence()
        ) {
            return;
        }

        // On a limited-capacity list only admittees (drawn) can attend, so presence cannot be set for someone still on
        // the waiting list.
        $list = $signup->getSignupList();
        if (
            $list->getLimitedCapacity()
            && !$signup->isDrawn()
        ) {
            return;
        }

        $signup->setPresent(!$signup->isPresent());

        // The list records that presence has been taken the first time anyone is marked; this drives the public
        // "presence taken" indicator and the review diff, and is never automatically unset.
        if (
            $signup->isPresent()
            && !$list->isPresenceTaken()
        ) {
            $list->setPresenceTaken(true);
        }

        $this->entityManager->flush();
    }

    /**
     * Manually admit/un-admit one sign-up (board or organiser) to backfill after the draw: promote someone from the
     * waiting list or drop a confirmed no-show. Only after the draw has been performed (and locked) and only until the
     * activity starts. Un-admitting also clears attendance: you cannot have attended without being admitted.
     * Overbooking past capacity is allowed (the template warns).
     */
    #[LiveAction]
    public function toggleAdmission(#[LiveArg]
    int $signupId,): void
    {
        $this->assertAccess();

        $signup = $this->findOwnedSignup($signupId);
        if (null === $signup) {
            return;
        }

        $list = $signup->getSignupList();
        // Manual admission needs an open window and either a locked draw (FCFS/conditional methods) or a manual method
        // (external-party/custom, which never run a draw).
        if (
            !$list->getLimitedCapacity()
            || !$this->admissionOpen()
            || (
                !$list->isDrawLocked()
                && !$list->getAllocationMethod()->isManual()
            )
        ) {
            return;
        }

        $admitted = !$signup->isDrawn();
        $signup->setDrawn($admitted);
        if (!$admitted) {
            $signup->setPresent(false);
        }

        $this->entityManager->flush();
    }

    #[LiveAction]
    public function assignRole(
        #[LiveArg]
        int $signupId,
        #[LiveArg]
        int $roleId,
    ): void {
        $this->assertAccess();

        $signup = $this->findOwnedSignup($signupId);
        if (null === $signup) {
            return;
        }

        $list = $signup->getSignupList();
        if (
            !$list->isClosed()
            || $list->isDrawLocked()
            || $list->getActivity()->isFrozen()
            || !$this->admissionOpen()
        ) {
            return;
        }

        $chosen = null;
        foreach ($list->getRoles() as $role) {
            if ($role->getId() !== $roleId) {
                continue;
            }

            $chosen = $role;

            break;
        }

        $signup->setRole($signup->getRole() === $chosen ? null : $chosen);
        $this->entityManager->flush();
    }

    /**
     * First-come-first-served draw (board only): admit the earliest sign-ups (ordered by id, i.e. creation) up to
     * capacity, waitlist the rest, and lock the draw.
     */
    #[LiveAction]
    public function drawFirstCome(#[LiveArg]
    int $listId,): void
    {
        $this->runDraw(
            $listId,
            AllocationMethod::FirstComeFirstServed,
        );
    }

    /**
     * Random lottery draw (board only): shuffle the sign-ups, then admit up to capacity and waitlist the rest.
     */
    #[LiveAction]
    public function drawLottery(#[LiveArg]
    int $listId,): void
    {
        $this->runDraw(
            $listId,
            AllocationMethod::ConditionalDraw,
        );
    }

    /**
     * Shared draw runner (board only): look up the owned list and hand it to the {@see DrawManager}, which checks the
     * draw may run for the given method, admits up to capacity (shuffled for a lottery) and locks it. Confirmed
     * client-side by a Bootstrap modal (see the `confirm-modal` Stimulus controller); re-checked server-side because
     * a live action is independent of the page that rendered it.
     */
    private function runDraw(
        int $listId,
        AllocationMethod $method,
    ): void {
        $this->assertAccess();
        $this->assertBoard();

        $list = $this->findOwnedList($listId);
        if (null === $list) {
            return;
        }

        $performed = $this->drawManager->drawManually(
            $list,
            $method,
            $this->currentMember(),
        );

        if ($performed) {
            return;
        }

        $this->setFeedback(
            AlertTypes::Warning,
            $this->translator->trans('The draw could not be run; the list may have changed or was already drawn.'),
        );
    }

    /**
     * The ticked signup ids as ints. The $selected LiveProp is fed checkbox values, which arrive as strings, while
     * selectAll() pushes ints and signup ids are ints; normalising avoids strict-comparison mismatches.
     *
     * @return int[]
     */
    private function selectedIds(): array
    {
        return $this->intList($this->selected);
    }

    /**
     * The hidden field-column ids as ints (the $hiddenFields LiveProp is mixed, like $selected).
     *
     * @return int[]
     */
    private function hiddenFieldIds(): array
    {
        return $this->intList($this->hiddenFields);
    }

    /**
     * Normalise a writable id-list LiveProp to ints: checkbox/JS hydration delivers ids as strings while the live
     * actions push ints, so the stored type is mixed; callers compare against int entity ids.
     *
     * @param list<int|string> $ids
     *
     * @return int[]
     */
    private function intList(array $ids): array
    {
        return array_map(
            static fn (int|string $id): int => (int) $id,
            $ids,
        );
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function selectAll(#[LiveArg]
    int $listId,): void
    {
        $this->assertAccess();

        // Select every subscriber on the list, regardless of the quick filter (the button says "Select all"); read the
        // already-selected ids as ints so a mix of checkbox-supplied strings and these ids dedupes correctly.
        $list = $this->findOwnedList($listId);
        if (null === $list) {
            return;
        }

        $selected = $this->selectedIds();
        foreach ($this->confirmedSignups($list) as $signup) {
            $id = $signup->getId();
            if (
                null === $id
                || in_array(
                    $id,
                    $selected,
                    true,
                )
            ) {
                continue;
            }

            $this->selected[] = $id;
            $selected[] = $id;
        }

        $this->forgetViews();
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function clearSelection(#[LiveArg]
    int $listId,): void
    {
        $this->assertAccess();

        // Selection is scoped per list: drop only this list's signup ids and leave any other list's selection intact.
        // Signup ids are globally unique, so the flat $selected set maps back to a single list unambiguously.
        $list = $this->findOwnedList($listId);
        if (null === $list) {
            return;
        }

        $listIds = [];
        foreach ($list->getSignUps() as $signup) {
            $id = $signup->getId();
            if (null === $id) {
                continue;
            }

            $listIds[] = $id;
        }

        $kept = [];
        foreach ($this->selectedIds() as $id) {
            if (
                in_array(
                    $id,
                    $listIds,
                    true,
                )
            ) {
                continue;
            }

            $kept[] = $id;
        }

        $this->selected = $kept;
        $this->forgetViews();
    }

    /**
     * Tick every row on screen, which in people mode is every sign-up of every person shown.
     */
    #[LiveAction]
    #[ReadOnlySafe]
    public function selectShown(): void
    {
        $this->assertAccess();

        $ids = [];
        if ($this->isPeopleMode()) {
            foreach ($this->getPeopleView()->rows as $row) {
                foreach ($row->signupIds as $id) {
                    $ids[] = $id;
                }
            }
        } else {
            $list = $this->getActiveList();
            foreach (null === $list ? [] : $list->rows as $row) {
                $ids[] = $row->signupId;
            }
        }

        $selected = $this->selectedIds();
        foreach ($ids as $id) {
            if (
                in_array(
                    $id,
                    $selected,
                    true,
                )
            ) {
                continue;
            }

            $this->selected[] = $id;
            $selected[] = $id;
        }

        $this->forgetViews();
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function clearAll(): void
    {
        $this->assertAccess();

        $this->selected = [];
        $this->forgetViews();
    }

    /**
     * The read-models are built once per render, and a selection made after they were built (which is how the rows
     * on screen were known) would render with the counts from before it. Built again on the next read.
     */
    private function forgetViews(): void
    {
        $this->listViews = null;
        $this->peopleView = null;
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function showList(#[LiveArg]
    int $listId,): void
    {
        $this->assertAccess();

        $this->mode = self::MODE_LIST;
        $this->activeListId = $listId;
        $this->quickFilter = SignupFilter::All->value;
        $this->composerOpen = false;
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function setMode(#[LiveArg]
    string $mode,): void
    {
        $this->assertAccess();

        $this->mode = self::MODE_PEOPLE === $mode
            ? self::MODE_PEOPLE
            : self::MODE_LIST;
        $this->quickFilter = SignupFilter::All->value;
        $this->composerOpen = false;
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function setQuickFilter(#[LiveArg]
    string $filter,): void
    {
        $this->assertAccess();

        $this->quickFilter = (SignupFilter::tryFrom($filter) ?? SignupFilter::All)->value;
    }

    /**
     * Show/hide one of the optional columns (generation, signed up at, other lists).
     */
    #[LiveAction]
    #[ReadOnlySafe]
    public function toggleColumn(#[LiveArg]
    string $column,): void
    {
        $this->assertAccess();

        $next = [];
        $found = false;
        foreach ($this->hiddenColumns as $hidden) {
            if ($hidden === $column) {
                $found = true;

                continue;
            }

            $next[] = $hidden;
        }

        if (!$found) {
            $next[] = $column;
        }

        $this->hiddenColumns = $next;
    }

    /**
     * Show/hide a sign-up field's column. Pure display state: a field id only matches the one list it belongs to,
     * so the hidden set is effectively per-list. A field absent from the set is shown (the default).
     */
    #[LiveAction]
    #[ReadOnlySafe]
    public function toggleFieldColumn(#[LiveArg]
    int $fieldId,): void
    {
        $this->assertAccess();

        $next = [];
        $found = false;
        foreach ($this->hiddenFieldIds() as $id) {
            if ($id === $fieldId) {
                $found = true;

                continue;
            }

            $next[] = $id;
        }

        if (!$found) {
            $next[] = $fieldId;
        }

        $this->hiddenFields = $next;
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function openComposer(#[LiveArg]
    ?string $scope = null,): void
    {
        $this->assertAccess();

        $this->composerOpen = true;
        // A hand-picked group is what somebody who ticked rows first came for.
        $fallback = [] === $this->selectedIds()
            ? RecipientScope::All
            : RecipientScope::Selected;
        $this->scope = (RecipientScope::tryFrom($scope ?? '') ?? $fallback)->value;
        $this->emailSubject = '';
        $this->emailBody = '';
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function setScope(#[LiveArg]
    string $scope,): void
    {
        $this->assertAccess();

        $this->scope = (RecipientScope::tryFrom($scope) ?? RecipientScope::All)->value;
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function closeComposer(): void
    {
        $this->composerOpen = false;
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function enterAttendance(#[LiveArg]
    int $listId,): void
    {
        $this->assertAccess();

        if (!$this->canMarkPresence()) {
            return;
        }

        $this->attendanceListId = $listId;
        $this->filter = '';
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function exitAttendance(): void
    {
        $this->assertAccess();

        $this->attendanceListId = null;
        $this->filter = '';
    }

    #[LiveAction]
    public function sendEmail(#[LiveArg]
    int $listId,): void
    {
        $this->assertAccess();

        if (!$this->composed()) {
            return;
        }

        $signupList = $this->findOwnedList($listId);
        if (null === $signupList) {
            return;
        }

        $scope = $this->scopeValue();
        if (RecipientScope::Multi === $scope) {
            $this->setFeedback(
                AlertTypes::Warning,
                $this->translator->trans('That recipient group is not available for this sign-up list.'),
            );

            return;
        }

        // Admitted/Waitlisted only distinguish recipients once admission is settled: a locked draw, or a manual
        // allocation method where admission is set by hand. On any other list every sign-up is not-yet-drawn, so the
        // two scopes would silently resolve to "everyone"/"no one". The composer only offers them in the same
        // circumstances, but $scope is a writable prop, so re-check it here.
        if (
            in_array(
                $scope,
                [
                    RecipientScope::Admitted,
                    RecipientScope::Waitlisted,
                ],
                true,
            )
            && !(
                $signupList->getLimitedCapacity()
                && (
                    $signupList->isDrawLocked()
                    || $signupList->getAllocationMethod()->isManual()
                )
            )
        ) {
            $this->setFeedback(
                AlertTypes::Warning,
                $this->translator->trans('That recipient group is not available for this sign-up list.'),
            );

            return;
        }

        $this->dispatchTo($this->recipientsFor(
            $signupList,
            $scope,
        ));
    }

    /**
     * Send to everybody on the activity in the recipient group on screen, one message per person.
     */
    #[LiveAction]
    public function sendToPeople(): void
    {
        $this->assertAccess();

        if (!$this->composed()) {
            return;
        }

        $scope = $this->scopeValue();
        if (RecipientScope::Admitted === $scope) {
            $this->setFeedback(
                AlertTypes::Warning,
                $this->translator->trans('That recipient group is not available for this sign-up list.'),
            );

            return;
        }

        $this->dispatchTo($this->recipientsAcrossLists($scope));
    }

    /**
     * Send the message on screen to the organiser alone, to read it the way a recipient will.
     */
    #[LiveAction]
    public function sendTest(): void
    {
        $this->assertAccess();

        if (!$this->composed()) {
            return;
        }

        $member = $this->currentMember();
        $email = $member->getEmail();
        if (
            null === $email
            || '' === $email
        ) {
            $this->setFeedback(
                AlertTypes::Warning,
                $this->translator->trans('You have no email address to send a test to.'),
            );

            return;
        }

        $this->dispatch(
            sprintf(
                '[%s] %s',
                $this->translator->trans('Test'),
                trim($this->emailSubject),
            ),
            [
                [
                    'email' => $email,
                    'name' => $member->getFullName(),
                ],
            ],
        );

        $this->setFeedback(
            AlertTypes::Success,
            $this->translator->trans('A test of your message is on its way to you.'),
        );
    }

    /**
     * Whether there is a message to send at all; says so when there is not.
     */
    private function composed(): bool
    {
        if (
            '' !== trim($this->emailSubject)
            && '' !== trim($this->emailBody)
        ) {
            return true;
        }

        $this->setFeedback(
            AlertTypes::Warning,
            $this->translator->trans('Please provide both a subject and a message.'),
        );

        return false;
    }

    /**
     * @param list<array{email: string, name: string}> $recipients
     */
    private function dispatchTo(array $recipients): void
    {
        if ([] === $recipients) {
            $this->setFeedback(
                AlertTypes::Warning,
                $this->translator->trans('There are no recipients in the selected group.'),
            );

            return;
        }

        $this->dispatch(
            trim($this->emailSubject),
            $recipients,
        );

        $this->setFeedback(
            AlertTypes::Success,
            $this->translator->trans(
                'Your message is being sent to %count% recipient(s).',
                ['%count%' => count($recipients)],
            ),
        );

        // Clear the composer so a reopened one starts blank.
        $this->emailSubject = '';
        $this->emailBody = '';
        $this->composerOpen = false;
    }

    /**
     * @param list<array{email: string, name: string}> $recipients
     */
    private function dispatch(
        string $subject,
        array $recipients,
    ): void {
        // Always render the activity name in English: the email's boilerplate is English regardless of the composing
        // organiser's locale (see OrganiserAnnouncementEmail), falling back to Dutch only when there is no English
        // name. A cancelled activity carries the (English) [CANCELLED] marker so recipients see it at a glance.
        $activityName = $this->activity->getName()->getText(Languages::English) ?? '';
        if ($this->activity->isCancelled()) {
            $activityName = '[CANCELLED] ' . $activityName;
        }

        $plain = [];
        foreach ($recipients as $recipient) {
            $plain[] = [
                'email' => $recipient['email'],
                'name' => $recipient['name'],
            ];
        }

        // One message carrying every recipient: a single, atomic enqueue (never a half-enqueued per-recipient fan-out).
        // The handler sends one email per recipient and tolerates an individual failure, so there is no duplicate
        // re-send on retry either.
        $this->messageBus->dispatch(
            new OrganiserAnnouncementEmail(
                $subject,
                trim($this->emailBody),
                $activityName,
                $this->replyTo(),
                $plain,
            ),
        );
    }

    /**
     * Resolve the concrete recipients of a bulk email for a list, by scope. External and member sign-ups alike carry
     * an email; any without one is skipped.
     *
     * @return list<array{email: string, name: string, external: bool}>
     */
    private function recipientsFor(
        SignupList $signupList,
        RecipientScope $scope,
    ): array {
        // Normalise once: the $selected LiveProp holds checkbox-supplied strings, while getId() is an int.
        $selected = $this->selectedIds();
        $recipients = [];
        foreach ($this->confirmedSignups($signupList) as $signup) {
            $external = !$signup instanceof UserSignup;
            $include = match ($scope) {
                RecipientScope::All => true,
                RecipientScope::Selected => in_array(
                    $signup->getId(),
                    $selected,
                    true,
                ),
                RecipientScope::Present => $signup->isPresent(),
                RecipientScope::Admitted => $signup->isDrawn(),
                RecipientScope::Waitlisted => !$signup->isDrawn(),
                RecipientScope::External => $external,
                RecipientScope::Multi => count($this->memberships()[$signup->personKey()] ?? []) > 1,
            };

            if (!$include) {
                continue;
            }

            $email = $signup->getEmail();
            if (null === $email) {
                continue;
            }

            $recipients[] = [
                'email' => $email,
                'name' => $signup->getFullName(),
                'external' => $external,
            ];
        }

        return $recipients;
    }

    /**
     * Find a sign-up by id, but only within this activity's live sign-up lists, so a crafted id cannot reach another
     * activity's sign-ups.
     */
    private function findOwnedSignup(int $signupId): ?Signup
    {
        foreach ($this->activity->getLiveSignupLists() as $signupList) {
            foreach ($this->confirmedSignups($signupList) as $signup) {
                if ($signup->getId() === $signupId) {
                    return $signup;
                }
            }
        }

        return null;
    }

    private function findOwnedList(int $listId): ?SignupList
    {
        foreach ($this->activity->getLiveSignupLists() as $signupList) {
            if ($signupList->getId() === $listId) {
                return $signupList;
            }
        }

        return null;
    }

    /**
     * A list's sign-ups excluding externals still awaiting email verification: an unconfirmed external is not a real
     * subscriber, so it must never be drawn, emailed, toggled or counted. Confirmation is exactly a set verification
     * moment (manually-added externals have it set immediately), so no extra query is needed.
     *
     * @return Signup[]
     */
    private function confirmedSignups(SignupList $signupList): array
    {
        $signups = [];
        foreach ($signupList->getSignUps() as $signup) {
            if (
                $signup instanceof ExternalSignup
                && null === $signup->getVerifiedAt()
            ) {
                continue;
            }

            $signups[] = $signup;
        }

        return $signups;
    }

    private function assertBoard(): void
    {
        if (!$this->isBoard()) {
            throw new AccessDeniedException();
        }
    }

    private function currentMember(): Member
    {
        $user = $this->security->getUser();
        assert($user instanceof User);

        return $user->getMember();
    }

    private function setFeedback(
        AlertTypes $type,
        string $message,
    ): void {
        $this->feedbackType = $type->value;
        $this->feedback = $message;
    }

    /**
     * The security boundary for every render and action: the viewer must be allowed to see the activity and be within
     * the viewing window (the board is never time-limited).
     */
    private function assertAccess(): void
    {
        if (
            !$this->security->isGranted(
                RevisionVoter::VIEW,
                $this->activity,
            )
            || !SignupAdminWindow::canView(
                $this->activity->getEndTime(),
                $this->security->isGranted(UserRoles::Board->value),
            )
        ) {
            throw new AccessDeniedException();
        }
    }
}
