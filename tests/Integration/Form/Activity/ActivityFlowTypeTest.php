<?php

declare(strict_types=1);

namespace App\Tests\Integration\Form\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\SignupList;
use App\Form\Activity\ActivityFlow\ActivityData;
use App\Form\Activity\ActivityFlow\ActivityFlowType;
use App\Service\Activity\ActivityDraftFactory;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Form\Flow\DataStorage\NullDataStorage;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The sign-up lists step. It is the one step that is not filled into the flow's data object but onto the revision,
 * which is built afresh on every request, so leaving the step and returning to it is what these pin.
 */
final class ActivityFlowTypeTest extends DatabaseTestCase
{
    public function testHandingInTheStepRequiresTheEnabledLanguages(): void
    {
        $flow = $this->submitLists(
            ['name' => ['valueEN' => '']],
            'finish',
        );

        self::assertFalse($flow->isValid());
        self::assertNotCount(
            0,
            $this->firstList($flow)->get('name')->get('valueEN')->getErrors(),
        );
    }

    /**
     * Going back asks for what was filled in to be kept, not for it to be correct: a half-written list must not hold
     * the organiser on the step it is trying to leave.
     */
    public function testGoingBackIsNotBlockedByTheStepsOwnChecks(): void
    {
        $flow = $this->submitLists(
            ['name' => ['valueEN' => '']],
            'previous',
        );

        self::assertTrue(
            $flow->isValid(),
            (string) $flow->getErrors(true),
        );
    }

    /**
     * What the step was filled in with lands on the data object, which is the only thing the flow carries from one
     * step to the next.
     */
    public function testLeavingTheStepRemembersWhatItHeld(): void
    {
        $data = $this->data();

        $this->submitLists(
            [],
            'previous',
            $data,
        );

        self::assertIsArray($data->signupListsSubmission);
        self::assertCount(
            1,
            $data->signupListsSubmission,
        );
    }

    /**
     * Returning to the step hands that back, which is what turns it into lists again on the fresh revision.
     */
    public function testReturningToTheStepFillsItBackIn(): void
    {
        $data = $this->data();

        $this->submitLists(
            [],
            'previous',
            $data,
        );

        $revision = $this->revision();
        $flow = $this->build(
            $data,
            $revision,
        );

        $flow->get(ActivityData::STEP_SIGNUP_LISTS)->submit(
            [ActivityData::STEP_SIGNUP_LISTS => $data->signupListsSubmission],
        );

        $lists = $revision->getSignupLists();
        self::assertCount(
            1,
            $lists,
        );

        $list = $lists->first();
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );
        self::assertSame(
            'Dinner',
            $list->getName()->getValueEN(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function submitLists(
        array $overrides,
        string $button,
        ?ActivityData $data = null,
    ): FormFlowInterface {
        $flow = $this->build(
            $data ?? $this->data(),
            $this->revision(),
        );

        $flow->submit([
            ActivityData::STEP_SIGNUP_LISTS => [
                'signupLists' => [$overrides +
                [
                    'name' => ['valueEN' => 'Dinner'],
                    'openDate' => '2030-01-01T12:00',
                    'closeDate' => '2030-02-01T12:00',
                    'allocationMethod' => AllocationMethod::FirstComeFirstServed->value,
                    'fields' => [],
                ],
                ],
            ],
            $button => '',
        ]);

        return $flow;
    }

    private function data(): ActivityData
    {
        $data = new ActivityData();
        $data->step = ActivityData::STEP_SIGNUP_LISTS;

        return $data;
    }

    private function revision(): ActivityRevision
    {
        return self::getContainer()->get(ActivityDraftFactory::class)->newRevision();
    }

    private function build(
        ActivityData $data,
        ActivityRevision $revision,
    ): FormFlowInterface {
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

    /**
     * @return FormInterface<mixed>
     */
    private function firstList(FormFlowInterface $flow): FormInterface
    {
        return $flow->get(ActivityData::STEP_SIGNUP_LISTS)->get('signupLists')->get('0');
    }
}
