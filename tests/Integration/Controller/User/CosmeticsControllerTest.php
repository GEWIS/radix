<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\CosmeticsController;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Entity\User\UserSettings;
use App\Repository\User\UserSettingsRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The navbar switch posts here, so the action returns no page and stores the flipped preference. Invoked directly, as
 * the rest of the suite does; the class-level ROLE_USER guard is enforced at the HTTP layer.
 */
final class CosmeticsControllerTest extends DatabaseTestCase
{
    private const int MEMBER = 8030;

    public function testTurningTheDecorationsOffIsStoredAndAnsweredWithoutContent(): void
    {
        $user = $this->authenticate();

        $response = self::getContainer()->get(CosmeticsController::class)(
            $this->request(['disabled' => '1']),
            $user,
        );

        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $response->getStatusCode(),
        );
        self::assertTrue($this->settings()->disableCosmetics);
    }

    public function testTheSwitchCanBeTurnedBackOn(): void
    {
        $user = $this->authenticate();

        $controller = self::getContainer()->get(CosmeticsController::class);
        $controller($this->request(['disabled' => '1']), $user);
        $controller($this->request(['disabled' => '0']), $user);

        self::assertFalse($this->settings()->disableCosmetics);
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
    private function request(array $body): Request
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = Request::create(
            '/en/user/cosmetics',
            'POST',
            $body,
        );
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
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
