<?php

declare(strict_types=1);

namespace App\Tests\Integration\Form\Activity;

use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\SignupList;
use App\Form\Activity\ActivityFlow\ActivityData;
use App\Form\Activity\ActivityFlow\ActivityFlowType;
use App\Form\Activity\Enums\SignupListSection;
use App\Service\Activity\ActivityDraftFactory;
use App\Tests\Integration\DatabaseTestCase;
use DateTime;
use Symfony\Component\Form\Flow\DataStorage\NullDataStorage;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormFactoryInterface;

use function array_slice;

final class ActivityFlowTypeTest extends DatabaseTestCase
{
    public function testEachSignupListGetsThreeStepsOfItsOwn(): void
    {
        $revision = $this->revisionWithLists(
            'Dinner',
            'Drinks',
        );

        $steps = $this->build($revision)->getCursor()->getSteps();

        self::assertSame(
            [
                ActivityData::STEP_GENERAL,
                ActivityData::STEP_DETAILS,
                ActivityData::STEP_SIGNUP_LISTS,
            ],
            array_slice(
                $steps,
                0,
                3,
            ),
        );

        $lists = $revision->getSignupLists()->getValues();
        self::assertSame(
            [
                ActivityFlowType::listStep(
                    $lists[0],
                    SignupListSection::Basics,
                ),
                ActivityFlowType::listStep(
                    $lists[0],
                    SignupListSection::Allocation,
                ),
                ActivityFlowType::listStep(
                    $lists[0],
                    SignupListSection::Questions,
                ),
                ActivityFlowType::listStep(
                    $lists[1],
                    SignupListSection::Basics,
                ),
                ActivityFlowType::listStep(
                    $lists[1],
                    SignupListSection::Allocation,
                ),
                ActivityFlowType::listStep(
                    $lists[1],
                    SignupListSection::Questions,
                ),
            ],
            array_slice(
                $steps,
                3,
            ),
        );
    }

    public function testRemovingAListLeavesTheOtherListsStepsAlone(): void
    {
        $revision = $this->revisionWithLists(
            'Dinner',
            'Drinks',
        );
        $lists = $revision->getSignupLists()->getValues();
        $second = ActivityFlowType::listStep(
            $lists[1],
            SignupListSection::Basics,
        );

        self::assertContains(
            $second,
            $this->build($revision)->getCursor()->getSteps(),
        );

        $revision->removeSignupList($lists[0]);

        self::assertContains(
            $second,
            $this->build($revision)->getCursor()->getSteps(),
        );
    }

    public function testAnActivityWithoutListsHasNothingBeyondTheOverview(): void
    {
        self::assertSame(
            [
                ActivityData::STEP_GENERAL,
                ActivityData::STEP_DETAILS,
                ActivityData::STEP_SIGNUP_LISTS,
            ],
            $this->build($this->revisionWithLists())->getCursor()->getSteps(),
        );
    }

    public function testTheOverviewCarriesNothingButItsOwnMarker(): void
    {
        $step = $this->build($this->revisionWithLists('Dinner'))->get(ActivityData::STEP_SIGNUP_LISTS);

        self::assertCount(
            1,
            $step,
        );
        self::assertTrue($step->has('open'));
        self::assertFalse($step->get('open')->getConfig()->getMapped());
    }

    /**
     * @return FormFlowInterface<mixed>
     */
    private function build(ActivityRevision $revision): FormFlowInterface
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

    private function revisionWithLists(string ...$names): ActivityRevision
    {
        $activity = self::getContainer()->get(ActivityDraftFactory::class)->newActivity(null);
        $revision = $activity->getCurrentRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );

        foreach ($names as $name) {
            $list = new SignupList();
            $list->setName(new ActivityLocalisedText(
                $name,
                $name,
            ));
            $list->setOpenDate(new DateTime('2030-01-01 12:00'));
            $list->setCloseDate(new DateTime('2030-02-01 12:00'));
            $revision->addSignupList($list);
        }

        return $revision;
    }
}
