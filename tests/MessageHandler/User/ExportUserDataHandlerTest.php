<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\User;

use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\Notification;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Message\User\ExportUserDataMessage;
use App\MessageHandler\User\ExportUserDataHandler;
use App\Repository\Decision\MemberRepository;
use App\Repository\User\UserRepository;
use App\Service\Application\FileStorage;
use App\Service\Application\NotificationPublisher;
use App\Service\User\GdprService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The export is written before the member is told, so the mail link always resolves. A member without an address
 * still gets the export and the in-app notice; a member without an account gets the mail but no notice.
 */
final class ExportUserDataHandlerTest extends TestCase
{
    private const array COLLECTED = [
        'member' => [
            'information' => ['lidnr' => 8025],
        ],
    ];

    public function testTheDataIsStoredThenAnnouncedAndMailed(): void
    {
        $storage = $this->storage();
        $sent = [];
        $notifications = [];
        $user = self::createStub(User::class);

        $this->handler(
            $storage,
            $sent,
            $notifications,
            $this->member('ada@example.com'),
            $user,
        )->__invoke(new ExportUserDataMessage(8025));

        $path = ExportUserDataHandler::exportPath(8025);
        self::assertTrue($storage->exists($path));
        self::assertSame(
            json_encode(
                self::COLLECTED,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            $storage->read($path),
        );
        self::assertSame(
            self::COLLECTED,
            json_decode(
                $storage->read($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertCount(
            1,
            $notifications,
        );
        self::assertSame(
            NotificationType::DataExportReady,
            $notifications[0]->type,
        );
        self::assertSame(
            $user,
            $notifications[0]->recipientUser,
        );

        self::assertCount(
            1,
            $sent,
        );
        self::assertSame(
            'ada@example.com',
            $sent[0]->getTo()[0]->getAddress(),
        );
        self::assertSame(
            'Your GEWIS data export is ready',
            $sent[0]->getSubject(),
        );
        self::assertSame(
            'emails/user/data-export.html.twig',
            $sent[0]->getHtmlTemplate(),
        );
        self::assertSame(
            'Ada Lovelace',
            $sent[0]->getContext()['fullName'],
        );
        self::assertSame(
            'https://gewis.nl/user_settings_data_export_download',
            $sent[0]->getContext()['downloadUrl'],
        );
    }

    public function testAMemberWithoutAnAddressStillGetsTheExportAndTheNotice(): void
    {
        $storage = $this->storage();
        $sent = [];
        $notifications = [];

        $this->handler(
            $storage,
            $sent,
            $notifications,
            $this->member(null),
            self::createStub(User::class),
        )->__invoke(new ExportUserDataMessage(8025));

        self::assertTrue($storage->exists(ExportUserDataHandler::exportPath(8025)));
        self::assertCount(
            1,
            $notifications,
        );
        self::assertSame(
            [],
            $sent,
        );
    }

    public function testAMemberWithoutAnAccountIsMailedButNotNotifiedInTheApp(): void
    {
        $storage = $this->storage();
        $sent = [];
        $notifications = [];

        $this->handler(
            $storage,
            $sent,
            $notifications,
            $this->member('ada@example.com'),
            null,
        )->__invoke(new ExportUserDataMessage(8025));

        self::assertCount(
            1,
            $sent,
        );
        self::assertSame(
            [],
            $notifications,
        );
    }

    public function testAMemberWhoIsGoneIsNotExported(): void
    {
        $storage = $this->storage();
        $sent = [];
        $notifications = [];

        $this->handler(
            $storage,
            $sent,
            $notifications,
            null,
            self::createStub(User::class),
        )->__invoke(new ExportUserDataMessage(987654));

        self::assertFalse($storage->exists(ExportUserDataHandler::exportPath(987654)));
        self::assertSame(
            [],
            $sent,
        );
        self::assertSame(
            [],
            $notifications,
        );
    }

    public function testAnExportOlderThanTheRetentionWindowIsNoLongerAvailable(): void
    {
        $operator = self::createStub(FilesystemOperator::class);
        $operator->method('fileExists')->willReturn(true);
        $operator->method('lastModified')->willReturn(
            new DateTimeImmutable('-4 days')->getTimestamp(),
        );

        self::assertFalse(ExportUserDataHandler::isAvailable(
            new FileStorage($operator),
            8025,
        ));
    }

    public function testAnExportInsideTheRetentionWindowIsAvailable(): void
    {
        $operator = self::createStub(FilesystemOperator::class);
        $operator->method('fileExists')->willReturn(true);
        $operator->method('lastModified')->willReturn(
            new DateTimeImmutable('-1 day')->getTimestamp(),
        );

        self::assertTrue(ExportUserDataHandler::isAvailable(
            new FileStorage($operator),
            8025,
        ));
    }

    public function testAnExportThatWasNeverMadeIsNotAvailable(): void
    {
        $operator = self::createStub(FilesystemOperator::class);
        $operator->method('fileExists')->willReturn(false);

        self::assertFalse(ExportUserDataHandler::isAvailable(
            new FileStorage($operator),
            8025,
        ));
    }

    /**
     * @param list<TemplatedEmail> $sent
     * @param list<Notification>   $notifications
     */
    private function handler(
        FileStorage $storage,
        array &$sent,
        array &$notifications,
        ?Member $member,
        ?User $user,
    ): ExportUserDataHandler {
        $members = self::createStub(MemberRepository::class);
        $members->method('findByLidnr')->willReturn($member);

        $gdpr = self::createStub(GdprService::class);
        $gdpr->method('collectMemberData')->willReturn(self::COLLECTED);

        $users = self::createStub(UserRepository::class);
        $users->method('find')->willReturn($user);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (Notification $notification) use (&$notifications): void {
                $notifications[] = $notification;
            },
        );

        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$sent): void {
                $sent[] = $email;
            },
        );

        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route): string => 'https://gewis.nl/' . $route,
        );

        return new ExportUserDataHandler(
            $members,
            $gdpr,
            $storage,
            $mailer,
            $urlGenerator,
            $users,
            new NotificationPublisher(
                $entityManager,
                new NullLogger(),
                [],
            ),
        );
    }

    private function storage(): FileStorage
    {
        return new FileStorage(new Filesystem(new InMemoryFilesystemAdapter()));
    }

    private function member(?string $email): Member
    {
        $member = self::createStub(Member::class);
        $member->lidnr = 8025;
        $member->email = $email;
        $member->method('getFullName')->willReturn('Ada Lovelace');

        return $member;
    }
}
