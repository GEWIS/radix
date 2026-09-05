<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Activity;

use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupRole;
use App\Form\Activity\ActivityFlow\ActivityData;
use App\Form\Activity\ActivityFlow\ActivityFlowType;
use App\Form\Activity\Enums\SignupListSection;
use App\Service\Activity\ActivityDraftFactory;
use App\Service\Application\RevisionDescriberRegistry;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\AnswersActivityForm;
use App\ViewModel\Application\Review\ReviewOutline;
use App\ViewModel\Application\Review\RevisionAudience;
use App\ViewModel\Application\Review\RevisionSection;
use DateTime;
use Symfony\Component\Form\Flow\DataStorage\NullDataStorage;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function strpos;
use function substr_count;

final class SignupListTemplatesTest extends DatabaseTestCase
{
    use AnswersActivityForm;

    public function testTheAllocationStepRendersEveryPriorityModifier(): void
    {
        $revision = $this->revisionWithList();
        $list = $revision->getSignupLists()->getValues()[0];
        $step = ActivityFlowType::listStep(
            $list,
            SignupListSection::Allocation,
        );

        $html = $this->renderStep(
            $revision,
            $step,
        );

        self::assertSame(
            3,
            substr_count(
                $html,
                'data-controller="tier-order"',
            ),
        );
        self::assertStringContainsString(
            'data-tier-order-value-param="third-year-and-above"',
            $html,
        );
        self::assertStringContainsString(
            'data-tier-order-value-param="bachelor"',
            $html,
        );
        self::assertLessThan(
            strpos(
                $html,
                'data-tier-order-value-param="ordinary"',
            ),
            (int) strpos(
                $html,
                'data-tier-order-value-param="graduate"',
            ),
        );
        self::assertStringNotContainsString(
            'data-tier-order-value-param="non-member"',
            $html,
        );
        // The seats are asked for on the rank they are held for, inside the order itself.
        self::assertStringContainsString(
            'data-tier-order-target="seats"',
            $html,
        );
        self::assertStringContainsString(
            'signup-role-collection',
            $html,
        );
    }

    public function testATiedRankIsRenderedAsOne(): void
    {
        $revision = $this->revisionWithList();
        $list = $revision->getSignupLists()->getValues()[0];
        $list->setOnlyGEWIS(false);
        $list->setMembershipTierOrder([
            [
                MembershipTier::Ordinary,
                MembershipTier::External,
                MembershipTier::Honorary,
                MembershipTier::Graduate,
            ],
            [MembershipTier::NonMember],
        ]);

        $html = $this->renderStep(
            $revision,
            ActivityFlowType::listStep(
                $list,
                SignupListSection::Allocation,
            ),
        );

        self::assertSame(
            3,
            substr_count(
                $html,
                'data-tier-order-tied-param="1"',
            ),
        );
        self::assertStringContainsString(
            'tier-order-entry--tied',
            $html,
        );
    }

    public function testAnOpenListIsNotOfferedTheStudyPhaseOrTheCohort(): void
    {
        $revision = $this->revisionWithList();
        $list = $revision->getSignupLists()->getValues()[0];
        $list->setOnlyGEWIS(false);

        $html = $this->renderStep(
            $revision,
            ActivityFlowType::listStep(
                $list,
                SignupListSection::Allocation,
            ),
        );

        self::assertSame(
            1,
            substr_count(
                $html,
                'data-controller="tier-order"',
            ),
        );
        self::assertStringNotContainsString(
            'data-tier-order-value-param="bachelor"',
            $html,
        );
        self::assertStringNotContainsString(
            'data-tier-order-value-param="third-year-and-above"',
            $html,
        );
        self::assertStringContainsString(
            'data-tier-order-value-param="non-member"',
            $html,
        );
    }

    public function testTheWindowSaysWhenTheActivityStarts(): void
    {
        $revision = $this->revisionWithList();
        $list = $revision->getSignupLists()->getValues()[0];

        $this->pushRequest();

        $html = $this->twig()->render(
            'partials/activity/admin/signup-list-section.html.twig',
            [
                'form' => $this->flow(
                    $revision,
                    ActivityFlowType::listStep(
                        $list,
                        SignupListSection::Basics,
                    ),
                    $this->answered(ActivityData::STEP_GENERAL),
                )->createView(),
                'step' => ActivityFlowType::listStep(
                    $list,
                    SignupListSection::Basics,
                ),
            ],
        );

        self::assertStringContainsString(
            'The activity starts on',
            $html,
        );
        self::assertStringContainsString(
            '1 Jun. 2030 18:00',
            $html,
        );
    }

    public function testAStepAsksForItsOwnSectionOnly(): void
    {
        $revision = $this->revisionWithList();
        $list = $revision->getSignupLists()->getValues()[0];

        $basics = $this->renderStep(
            $revision,
            ActivityFlowType::listStep(
                $list,
                SignupListSection::Basics,
            ),
        );

        self::assertStringContainsString(
            'closeDate',
            $basics,
        );
        self::assertStringNotContainsString(
            'allocationMethod',
            $basics,
        );
        self::assertStringNotContainsString(
            'data-controller="tier-order"',
            $basics,
        );
    }

    public function testAPartIsMarkedOnceItHoldsWhatItNeeds(): void
    {
        $revision = $this->revisionWithList();
        $list = $revision->getSignupLists()->getValues()[0];
        $list->setOpenDate(null);
        $list->setCloseDate(null);

        $this->pushRequest();

        $html = $this->twig()->render(
            'partials/activity/admin/form.html.twig',
            [
                'form' => $this->flow(
                    $revision,
                    ActivityFlowType::listStep(
                        $list,
                        SignupListSection::Allocation,
                    ),
                )->createView(),
                'activity' => $revision->getActivity(),
            ],
        );

        self::assertStringContainsString(
            'nav-link is-done',
            $html,
        );
        self::assertStringContainsString(
            '2/3',
            $html,
        );
    }

    public function testAStepWhoseQuestionsAreAnsweredCanBeGoneToFromTheHeader(): void
    {
        $revision = $this->revisionWithList();
        $activity = $revision->getActivity();

        $this->pushRequest();

        $flow = $this->flow(
            $revision,
            ActivityData::STEP_GENERAL,
            $this->answered(ActivityData::STEP_GENERAL),
        );

        $html = $this->twig()->render(
            'partials/activity/admin/form.html.twig',
            [
                'form' => $flow->createView(),
                'activity' => $activity,
            ],
        );

        self::assertStringContainsString(
            'value="' . ActivityData::STEP_SIGNUP_LISTS . '"',
            $html,
        );
    }

    public function testAStepThatIsNotAnsweredYetIsNotOffered(): void
    {
        $revision = $this->revisionWithList();

        $this->pushRequest();

        $html = $this->twig()->render(
            'partials/activity/admin/form.html.twig',
            [
                'form' => $this->flow(
                    $revision,
                    ActivityData::STEP_GENERAL,
                )->createView(),
                'activity' => $revision->getActivity(),
            ],
        );

        self::assertStringNotContainsString(
            'value="' . ActivityData::STEP_SIGNUP_LISTS . '"',
            $html,
        );
    }

    public function testTheJumpButtonIsNotAlsoEmittedAtTheEndOfTheForm(): void
    {
        $html = $this->renderForm(SignupListSection::Allocation);

        self::assertStringNotContainsString(
            'id="activity_flow_goto"',
            $html,
        );
    }

    public function testTheHeaderShowsTheListBeingFilledInAndItsOwnSteps(): void
    {
        $html = $this->renderForm(SignupListSection::Allocation);

        self::assertStringContainsString(
            'form-stepper-group',
            $html,
        );
        self::assertStringContainsString(
            'nav nav-pills',
            $html,
        );
        self::assertSame(
            3,
            substr_count(
                $html,
                'nav-item',
            ),
        );
        self::assertStringContainsString(
            'nav-link active',
            $html,
        );
        self::assertStringContainsString(
            'nav-link is-done',
            $html,
        );
        self::assertStringContainsString(
            '3/3',
            $html,
        );
        self::assertStringContainsString(
            'Participants',
            $html,
        );
        self::assertStringContainsString(
            'Jump to sign-up list',
            $html,
        );
        self::assertStringContainsString(
            'All sign-up lists',
            $html,
        );
    }

    public function testTheReviewShowsTheAllocationSettingsAndWhatTheyWere(): void
    {
        $previous = $this->revisionWithList();
        $previousList = $previous->getSignupLists()->getValues()[0];
        $previousList->setOrganisingCommitteeSeats(1);

        $revision = $this->revisionWithList();
        $list = $revision->getSignupLists()->getValues()[0];
        $list->setLineageId($previousList->getLineageId());
        $list->setOrganisingCommitteeSeats(4);

        $html = $this->renderPane(
            $revision,
            $previous,
            SignupListSection::Allocation->keyFor($list),
        );

        self::assertStringContainsString(
            'Capacity',
            $html,
        );
        self::assertStringContainsString(
            'Membership priority',
            $html,
        );
        self::assertStringContainsString(
            'Driver (2)',
            $html,
        );
        self::assertStringContainsString(
            '<del>1</del><ins>4</ins>',
            $html,
        );
    }

    public function testTheReviewOutlineStepsThroughEachSignupList(): void
    {
        $revision = $this->revisionWithList();
        $list = $revision->getSignupLists()->getValues()[0];

        $html = $this->renderOutline(
            $revision,
            null,
            SignupListSection::Basics->keyFor($list),
        );

        self::assertStringContainsString(
            'Participants',
            $html,
        );
        self::assertStringContainsString(
            'Basics',
            $html,
        );
        self::assertStringContainsString(
            'Allocation',
            $html,
        );
        self::assertStringContainsString(
            'Questions',
            $html,
        );
    }

    private function renderPane(
        ActivityRevision $revision,
        ?ActivityRevision $previous,
        string $key,
    ): string {
        $sections = $this->sections(
            $revision,
            $previous,
        );
        $section = ReviewOutline::sectionFor(
            $sections,
            $key,
        );
        self::assertNotNull($section);

        $this->pushRequest();

        return $this->twig()->render(
            'partials/application/review-pane.html.twig',
            ['section' => $section],
        );
    }

    private function renderOutline(
        ActivityRevision $revision,
        ?ActivityRevision $previous,
        string $key,
    ): string {
        $sections = $this->sections(
            $revision,
            $previous,
        );

        $this->pushRequest();

        return $this->twig()->render(
            'partials/application/review-outline.html.twig',
            [
                'outline' => ReviewOutline::of(
                    $sections,
                    $key,
                    self::getContainer()->get(TranslatorInterface::class),
                ),
            ],
        );
    }

    /**
     * @return list<RevisionSection>
     */
    private function sections(
        ActivityRevision $revision,
        ?ActivityRevision $previous,
    ): array {
        return self::getContainer()->get(RevisionDescriberRegistry::class)
            ->describe(
                $revision,
                $previous,
            )
            ->sectionsFor(RevisionAudience::ReviewerOnly);
    }

    private function list(): SignupList
    {
        $list = new SignupList();
        $list->setName(new ActivityLocalisedText(
            'Participants',
            'Participants',
        ));
        $list->setOpenDate(new DateTime('2030-01-01 12:00'));
        $list->setCloseDate(new DateTime('2030-02-01 12:00'));
        $list->setOnlyGEWIS(true);
        $list->setLimitedCapacity(true);
        $list->setCapacity(10);
        $list->setMembershipTierOrder([
            [MembershipTier::Graduate],
            [MembershipTier::Ordinary],
        ]);
        $list->setMembershipPriorityMode(MembershipPriorityMode::ReservedSeats);
        $list->setHeldMembershipSeats([MembershipTier::Ordinary->value => 2]);

        $role = new SignupRole();
        $role->setName('Driver');
        $role->setMinimum(2);
        $list->addRole($role);

        return $list;
    }

    private function renderForm(SignupListSection $section): string
    {
        $revision = $this->revisionWithList();
        $activity = $revision->getActivity();
        $list = $revision->getSignupLists()->getValues()[0];
        $step = ActivityFlowType::listStep(
            $list,
            $section,
        );

        $this->pushRequest();

        return $this->twig()->render(
            'partials/activity/admin/form.html.twig',
            [
                'form' => $this->flow(
                    $revision,
                    $step,
                )->createView(),
                'activity' => $activity,
            ],
        );
    }

    private function renderStep(
        ActivityRevision $revision,
        string $step,
    ): string {
        $flow = $this->flow(
            $revision,
            $step,
        );

        return $this->twig()->render(
            'partials/activity/admin/signup-list-section.html.twig',
            [
                'form' => $flow->createView(),
                'step' => $step,
            ],
        );
    }

    private function flow(
        ActivityRevision $revision,
        string $step = ActivityData::STEP_SIGNUP_LISTS,
        ?ActivityData $data = null,
    ): FormFlowInterface {
        $data ??= new ActivityData();
        $data->step = $step;

        $flow = self::getContainer()->get(FormFactoryInterface::class)->create(
            ActivityFlowType::class,
            $data,
            [
                'csrf_protection' => false,
                'data_storage' => new NullDataStorage(),
                'revision' => $revision,
            ],
        );
        self::assertInstanceOf(
            FormFlowInterface::class,
            $flow,
        );

        return $flow;
    }

    private function revisionWithList(): ActivityRevision
    {
        $activity = self::getContainer()->get(ActivityDraftFactory::class)->newActivity(null);
        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $revision->addSignupList($this->list());

        return $revision;
    }

    private function pushRequest(): void
    {
        $request = new Request();
        $request->setSession(self::getContainer()->get('session.factory')->createSession());
        self::getContainer()->get('request_stack')->push($request);
    }

    private function twig(): Environment
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(
            Environment::class,
            $twig,
        );

        return $twig;
    }
}
