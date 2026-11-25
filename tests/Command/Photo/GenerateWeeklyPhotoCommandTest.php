<?php

declare(strict_types=1);

namespace App\Tests\Command\Photo;

use App\Command\Photo\GenerateWeeklyPhotoCommand;
use App\Entity\Photo\Photo;
use App\Repository\Photo\PhotoRepository;
use App\Repository\Photo\VoteRepository;
use App\Repository\Photo\WeeklyPhotoRepository;
use App\Service\Application\FileStorage;
use App\Service\Photo\WeeklyPhotoService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The photo of the week is chosen before the board's hide window, so a run that picks nothing leaves the frontpage
 * without one. No votes is a reported non-event, and a forced photo becomes the week's photo.
 */
#[CoversClass(GenerateWeeklyPhotoCommand::class)]
final class GenerateWeeklyPhotoCommandTest extends TestCase
{
    public function testAWeekThatIsNotADateIsRefused(): void
    {
        $tester = $this->tester(
            $this->service(
                $this->storage(),
                [],
            ),
            $this->photos(),
        );

        self::assertSame(
            Command::INVALID,
            $tester->execute(['--week' => 'the week after Pentecost']),
        );
        self::assertStringContainsString(
            'Could not parse the week start date',
            $tester->getDisplay(),
        );
    }

    /**
     * A week nobody voted is not a failed run: the cron reports it and exits 0, and no photo of the week is published.
     */
    public function testAWeekWithoutVotesReportsItAndSucceeds(): void
    {
        $storage = $this->storage();

        $tester = $this->tester(
            $this->service(
                $storage,
                [],
            ),
            $this->photos(),
        );

        self::assertSame(
            Command::SUCCESS,
            $tester->execute(['--week' => '2026-07-06']),
        );
        self::assertStringContainsString(
            'No photo of the week was chosen',
            $tester->getDisplay(),
        );
        self::assertSame(
            [],
            $storage->listFiles('photos/weekly'),
        );
    }

    public function testAForcedPhotoWithAnUnknownIdIsRefused(): void
    {
        $tester = $this->tester(
            $this->service(
                $this->storage(),
                [],
            ),
            $this->photos(),
        );

        self::assertSame(
            Command::INVALID,
            $tester->execute(['--photo' => '99']),
        );
        self::assertStringContainsString(
            'No photo with id "99".',
            $tester->getDisplay(),
        );
    }

    /**
     * A forced photo skips the vote, and the public copy must exist for the week chosen, not for the week that just
     * passed.
     */
    public function testAForcedPhotoBecomesThePhotoOfTheWeek(): void
    {
        $storage = $this->storage();
        $photo = $this->photo(
            $storage,
            7,
        );

        $tester = $this->tester(
            $this->service(
                $storage,
                [],
            ),
            $this->photos($photo),
        );

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([
                '--photo' => '7',
                '--week' => '2026-07-06',
            ]),
        );

        self::assertTrue(
            $storage->exists($photo->path),
            'The original stays where the album keeps it.',
        );
        self::assertTrue(
            $storage->exists('photos/weekly/7.jpg'),
            'The anonymous frontpage reads the public copy, which has to be published with the choice.',
        );
        self::assertStringContainsString(
            'Photo of the week set to photo 7.',
            $tester->getDisplay(),
        );
    }

    private function tester(
        WeeklyPhotoService $service,
        PhotoRepository $photoRepository,
    ): CommandTester {
        return new CommandTester(new GenerateWeeklyPhotoCommand(
            $service,
            $photoRepository,
            self::createStub(LoggerInterface::class),
        ));
    }

    /**
     * The real selection service; the votes and history are stubbed to the calendar this case needs.
     *
     * @param array<array-key, array{1: int, 2: int}> $votes
     */
    private function service(
        FileStorage $storage,
        array $votes,
    ): WeeklyPhotoService {
        $voteRepository = self::createStub(VoteRepository::class);
        $voteRepository->method('getVotesInRange')->willReturn($votes);

        $weeklyPhotoRepository = self::createStub(WeeklyPhotoRepository::class);
        $weeklyPhotoRepository->method('getCurrentPhotoOfTheWeek')->willReturn(null);
        $weeklyPhotoRepository->method('hasBeenPhotoOfTheWeek')->willReturn(false);

        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $message): Envelope => new Envelope($message),
        );

        return new WeeklyPhotoService(
            $this->photos(),
            $voteRepository,
            $weeklyPhotoRepository,
            $storage,
            self::createStub(EntityManagerInterface::class),
            $messageBus,
        );
    }

    private function photos(Photo ...$photos): PhotoRepository
    {
        $byId = [];
        foreach ($photos as $photo) {
            self::assertNotNull($photo->id);
            $byId[$photo->id] = $photo;
        }

        $repository = self::createStub(PhotoRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (mixed $id): ?Photo => $byId[(int) $id] ?? null,
        );

        return $repository;
    }

    private function storage(): FileStorage
    {
        return new FileStorage(new Filesystem(new InMemoryFilesystemAdapter()));
    }

    private function photo(
        FileStorage $storage,
        int $id,
    ): Photo {
        $path = 'photos/' . $id . '/original.jpg';
        $storage->write(
            $path,
            'the original bytes',
        );

        $photo = new Photo();
        new ReflectionProperty(
            Photo::class,
            'id',
        )->setValue(
            $photo,
            $id,
        );
        $photo->path = $path;
        $photo->dateTime = new DateTimeImmutable('-3 days');

        return $photo;
    }
}
