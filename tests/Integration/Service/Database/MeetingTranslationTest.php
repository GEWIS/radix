<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Database;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\SubDecision\Other;
use App\Service\Database\Meeting;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function in_array;

/**
 * Every write is rolled back by dama/doctrine-test-bundle, so the seed these decisions are added to survives the run.
 */
#[CoversClass(Meeting::class)]
class MeetingTranslationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Meeting $meetingService;
    private TranslatorInterface $translator;
    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->meetingService = self::getContainer()->get(Meeting::class);
        $this->translator = self::getContainer()->get(TranslatorInterface::class);
        $this->build = new LedgerBuilder($this->entityManager);
    }

    public function testOffersOnlyTheDecisionsThatHaveNoEnglishText(): void
    {
        $meeting = $this->build->meeting(MeetingTypes::BV);
        $untranslated = $this->build->decideFreely(
            $meeting,
            'Het bestuur besluit een taart te kopen.',
        );
        $translated = $this->build->decideFreely(
            $meeting,
            'Het bestuur besluit een tweede taart te kopen.',
            'The board decides to buy a second cake.',
        );

        $offered = $this->offeredContents();

        self::assertTrue(in_array(
            $untranslated->getContentNL(),
            $offered,
            true,
        ));
        self::assertFalse(in_array(
            $translated->getContentNL(),
            $offered,
            true,
        ));
    }

    public function testTranslatingADecisionTakesItOffThePage(): void
    {
        $other = $this->build->decideFreely(
            $this->build->meeting(),
            'Het bestuur besluit een taart te kopen.',
        );

        $other->setContentEN('The board decides to buy a cake.');
        $this->meetingService->translateDecision($other);

        self::assertSame(
            'Het bestuur besluit een taart te kopen.',
            $other->getTranslatedContent(
                $this->translator,
                AppLanguages::Dutch,
            ),
        );
        self::assertSame(
            'The board decides to buy a cake.',
            $other->getTranslatedContent(
                $this->translator,
                AppLanguages::English,
            ),
        );
        self::assertFalse(in_array(
            $other->getContentNL(),
            $this->offeredContents(),
            true,
        ));
        self::assertNull($this->meetingService->getUntranslatedDecision(
            $other->getMeetingType(),
            $other->getMeetingNumber(),
            $other->getDecisionPoint(),
            $other->getDecisionNumber(),
            $other->getSequence(),
        ));
    }

    /**
     * @return string[]
     */
    private function offeredContents(): array
    {
        return array_map(
            static fn (Other $other): string => $other->getContentNL(),
            $this->meetingService->getUntranslatedDecisions(
                1,
                100,
            )['items'],
        );
    }
}
