<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Database;

use App\Service\Database\Meeting;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function count;

/**
 * The lookup the decision forms pick a decision from.
 *
 * It fires on every keystroke, and its one predicate is a reference written out and compared with LIKE, which no
 * index can serve. What it may read therefore has to be bounded by the query rather than by what somebody types.
 */
class MeetingSearchTest extends KernelTestCase
{
    private Meeting $meetingService;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->meetingService = self::getContainer()->get(Meeting::class);
    }

    /**
     * Every reference has a dot in it, so this is the broadest prompt there is.
     */
    public function testABroadPromptIsCappedRatherThanAnsweredInFull(): void
    {
        $matches = $this->meetingService->searchDecisions('.');

        self::assertNotEmpty($matches);
        self::assertLessThanOrEqual(
            25,
            count($matches),
        );
    }
}
