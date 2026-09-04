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
use App\Service\Activity\ActivityDraftFactory;
use App\Tests\Integration\DatabaseTestCase;
use DateTime;
use Symfony\Component\Form\Flow\DataStorage\NullDataStorage;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

use function str_contains;
use function strpos;
use function substr_count;

final class SignupListTemplatesTest extends DatabaseTestCase
{
    public function testTheEditorRendersEveryPriorityModifier(): void
    {
        // Through newActivity(), because a list only renders as part of an activity: the editor asks the activity
        // whether it has already started before it decides what may still be changed.
        $activity = self::getContainer()->get(ActivityDraftFactory::class)->newActivity(null);
        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $revision->addSignupList($this->list());

        $flow = $this->flow($revision);
        $view = $flow->get(ActivityData::STEP_SIGNUP_LISTS)->get('signupLists')->createView();

        $html = $this->twig()
            ->createTemplate('{{ form_widget(form) }}')
            ->render(['form' => $view]);

        // Each modifier is a tier-order control with its tiers in the order the list serves them.
        self::assertSame(
            3,
            substr_count(
                $html,
                'data-controller="tier-order"',
            ),
        );
        self::assertStringContainsString(
            'data-tier-order-value-param="non-member"',
            $html,
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
                'data-tier-order-value-param="non-member"',
            ),
        );
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

    public function testTheReviewDiffShowsTheAllocationSettingsAndWhatTheyWere(): void
    {
        $previous = $this->list();
        $previous->setOrganisingCommitteeSeats(1);

        $current = $this->list();
        $current->setOrganisingCommitteeSeats(4);

        $html = $this->twig()->render(
            'partials/activity/signup-list-diff.html.twig',
            [
                'signupListDiff' => [
                    'present' => [
                        [
                            'list' => $current,
                            'previous' => $previous,
                            'liveAdmitted' => 0,
                        ],
                    ],
                    'removed' => [],
                ],
            ],
        );

        // The board sees the capacity and the method, which the review never showed before, and the modifiers with
        // them.
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
        // A changed setting carries what it was.
        self::assertTrue(str_contains(
            $html,
            '<del class="text-danger">1</del>',
        ));
    }

    private function list(): SignupList
    {
        $list = new SignupList();
        $list->setName(new ActivityLocalisedText(
            'Deelnemers',
            'Participants',
        ));
        $list->setOpenDate(new DateTime('2030-01-01 12:00'));
        $list->setCloseDate(new DateTime('2030-02-01 12:00'));
        $list->setLimitedCapacity(true);
        $list->setCapacity(10);
        $list->setMembershipTierOrder([
            [MembershipTier::NonMember],
            [MembershipTier::Ordinary],
            [MembershipTier::Graduate],
        ]);
        $list->setMembershipPriorityMode(MembershipPriorityMode::ReservedSeats);
        $list->setHeldMembershipSeats([MembershipTier::Ordinary->value => 2]);

        $role = new SignupRole();
        $role->setName('Driver');
        $role->setMinimum(2);
        $list->addRole($role);

        return $list;
    }

    private function flow(ActivityRevision $revision): FormFlowInterface
    {
        $data = new ActivityData();
        $data->step = ActivityData::STEP_SIGNUP_LISTS;

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
