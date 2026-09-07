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
use App\Tests\Support\AnswersActivityForm;
use DateTime;
use Symfony\Component\Form\Flow\DataStorage\NullDataStorage;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormFactoryInterface;

use function array_slice;

final class ActivityFlowTypeTest extends DatabaseTestCase
{
    use AnswersActivityForm;

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

    public function testFinishingIsRefusedWhileAnEarlierStepIsWanting(): void
    {
        $revision = $this->revisionWithLists();
        $data = $this->answered(ActivityData::STEP_SIGNUP_LISTS);
        $data->nameEN = null;

        $flow = $this->build(
            $revision,
            $data,
        );
        $flow->submit([
            'finish' => '',
        ]);

        self::assertFalse($flow->isFinished());
        self::assertStringContainsString(
            'Details',
            (string) $flow->getErrors(),
        );
    }

    public function testFinishingIsRefusedWhileASignupListIsUnfinished(): void
    {
        $revision = $this->revisionWithLists('');

        $flow = $this->build(
            $revision,
            $this->answered(ActivityData::STEP_SIGNUP_LISTS),
        );
        $flow->submit(['finish' => '']);

        self::assertFalse($flow->isFinished());
        self::assertStringContainsString(
            'Basics',
            (string) $flow->getErrors(),
        );
    }

    public function testAListNamedInOneLanguageIsUnfinishedWhileTheActivityIsWrittenInTwo(): void
    {
        $revision = $this->revisionWithLists('Dinner');
        $revision->getSignupLists()->getValues()[0]->setName(new ActivityLocalisedText('Dinner'));
        $data = $this->answered(ActivityData::STEP_SIGNUP_LISTS);
        $data->languageDutch = true;
        $data->nameNL = 'Testactiviteit';
        $data->locationNL = 'Aula';
        $data->costsNL = 'Gratis';
        $data->descriptionNL = 'Een praatje.';

        $flow = $this->build(
            $revision,
            $data,
        );
        $flow->submit(['finish' => '']);

        self::assertFalse($flow->isFinished());
        self::assertStringContainsString(
            'Basics',
            (string) $flow->getErrors(),
        );

        $data->languageDutch = false;
        $flow = $this->build(
            $revision,
            $data,
        );
        $flow->submit(['finish' => '']);

        self::assertTrue(
            $flow->isFinished(),
            (string) $flow->getErrors(),
        );
    }

    public function testFinishingIsAllowedWhenEveryStepHoldsTogether(): void
    {
        $flow = $this->build(
            $this->revisionWithLists(),
            $this->answered(ActivityData::STEP_SIGNUP_LISTS),
        );
        $flow->submit([
            'finish' => '',
        ]);

        self::assertTrue($flow->isFinished());
    }

    /**
     * @return FormFlowInterface<mixed>
     */
    private function build(
        ActivityRevision $revision,
        ?ActivityData $data = null,
    ): FormFlowInterface {
        $data ??= new ActivityData();
        $data->step ??= ActivityData::STEP_SIGNUP_LISTS;

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
