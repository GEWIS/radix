<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\SettingsController;
use App\Entity\User\DataExportRequest;
use App\Entity\User\Enums\ColourVision;
use App\Entity\User\Enums\PhotoVisibility;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Entity\User\UserSettings;
use App\Message\User\ExportUserDataMessage;
use App\MessageHandler\User\ExportUserDataHandler;
use App\Repository\User\UserSettingsRepository;
use App\Security\User\SudoMode;
use App\Service\Application\FileDownloadHelper;
use App\Service\Application\FileStorage;
use App\Service\Photo\MemberTagPurgeService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_filter;

/**
 * The member's settings pages apart from the notification table: the two preference forms, the irreversible tag purge,
 * and the data export. Invoked directly, as the rest of the suite does; the class-level ROLE_USER guard and sudo
 * requirement are enforced at the HTTP layer.
 */
final class SettingsControllerFormsTest extends DatabaseTestCase
{
    private const int MEMBER = 8030;

    public function testTheGeneralFormRenders(): void
    {
        $user = $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(SettingsController::class)->general(
            new Request(),
            $user,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Disable festive effects',
            (string) $response->getContent(),
        );
    }

    public function testSavingTheGeneralFormStoresTheColourVision(): void
    {
        $user = $this->authenticate();
        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'disableCosmetics' => '1',
            'colourVision' => ColourVision::RedGreen->value,
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(SettingsController::class)->general(
            $request,
            $user,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        $settings = $this->settings();
        self::assertTrue($settings->disableCosmetics);
        self::assertSame(
            ColourVision::RedGreen,
            $settings->colourVision,
        );
    }

    public function testSavingThePrivacyFormStoresTheOptOuts(): void
    {
        $user = $this->authenticate();
        $request = $this->postRequest(
            [
                '_csrf_token' => 'csrf-token',
                'photoTaggingOptOut' => '1',
                'photoVisibility' => PhotoVisibility::HideAll->value,
                'hideYearOfBirth' => '1',
                'hideBirthdayOnFrontpage' => '1',
            ],
            'privacy_settings',
        );
        $this->pushRequest($request);

        $response = self::getContainer()->get(SettingsController::class)->privacy(
            $request,
            self::getContainer()->get(SudoMode::class),
            self::getContainer()->get(FileStorage::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        $settings = $this->settings();
        self::assertTrue($settings->photoTaggingOptOut);
        self::assertSame(
            PhotoVisibility::HideAll,
            $settings->photoVisibility,
        );
        self::assertTrue($settings->hideYearOfBirth);
        self::assertTrue($settings->hideBirthdayOnFrontpage);
    }

    public function testTheTagPurgeRemovesTheTagsAndReturnsToPrivacy(): void
    {
        $user = $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(SettingsController::class)->purgeTags(
            self::getContainer()->get(MemberTagPurgeService::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/user/settings/privacy',
            self::redirectTarget($response),
        );
    }

    public function testRequestingAnExportQueuesTheJob(): void
    {
        $user = $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(SettingsController::class)->requestDataExport(
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(FileStorage::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            1,
            $this->entityManager->getRepository(DataExportRequest::class)->count([]),
        );

        $transport = self::getContainer()->get('messenger.transport.bulk');
        self::assertInstanceOf(
            InMemoryTransport::class,
            $transport,
        );
        self::assertNotEmpty(array_filter(
            $transport->getSent(),
            static fn ($envelope): bool => $envelope->getMessage() instanceof ExportUserDataMessage,
        ));
    }

    public function testASecondRequestWhileOneIsBeingPreparedIsNotQueued(): void
    {
        $user = $this->authenticate();
        $this->pushRequest(new Request());

        $service = self::getContainer()->get(SettingsController::class);
        $service->requestDataExport(
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(FileStorage::class),
            $user,
        );

        $request = new Request();
        $request->setSession($this->session());
        self::getContainer()->get('request_stack')->push($request);

        $service->requestDataExport(
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(FileStorage::class),
            $user,
        );

        self::assertSame(
            1,
            $this->entityManager->getRepository(DataExportRequest::class)->count([]),
        );
        self::assertContains(
            'Your data export is already being prepared.',
            self::flashBag($request)->get('info'),
        );
    }

    public function testAnExportThatIsAlreadyDownloadableIsNotRequestedAgain(): void
    {
        $user = $this->authenticate();
        $request = new Request();
        $request->setSession($this->session());
        self::getContainer()->get('request_stack')->push($request);

        self::getContainer()->get(FileStorage::class)->write(
            ExportUserDataHandler::exportPath(self::MEMBER),
            '{}',
        );

        $response = self::getContainer()->get(SettingsController::class)->requestDataExport(
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(FileStorage::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            0,
            $this->entityManager->getRepository(DataExportRequest::class)->count([]),
        );
    }

    public function testDownloadingWithoutAnExportIsNotFound(): void
    {
        $user = $this->authenticate();
        $this->pushRequest(new Request());

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(SettingsController::class)->downloadDataExport(
            self::getContainer()->get(FileStorage::class),
            self::getContainer()->get(FileDownloadHelper::class),
            $user,
        );
    }

    public function testAnExportIsServedAsADownload(): void
    {
        $user = $this->authenticate();
        $this->pushRequest(new Request());

        self::getContainer()->get(FileStorage::class)->write(
            ExportUserDataHandler::exportPath(self::MEMBER),
            '{"lidnr":8030}',
        );

        $response = self::getContainer()->get(SettingsController::class)->downloadDataExport(
            self::getContainer()->get(FileStorage::class),
            self::getContainer()->get(FileDownloadHelper::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            'application/json',
            $response->headers->get('Content-Type'),
        );
        self::assertStringContainsString(
            'gewis-data-export.json',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    private function settings(): UserSettings
    {
        $settings = self::getContainer()->get(UserSettingsRepository::class)->find(self::MEMBER);
        self::assertInstanceOf(
            UserSettings::class,
            $settings,
        );

        return $settings;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postRequest(
        array $body,
        string $name = 'general_settings',
    ): Request {
        $request = Request::create(
            '/en/user/settings',
            'POST',
            [$name => $body],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        return $request;
    }

    private function pushRequest(Request $request): void
    {
        $session = $this->session();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    private function session(): SessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        return $session;
    }

    private function authenticate(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(self::MEMBER);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            [UserRoles::User->value],
        ));

        return $user;
    }
}
