<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\MeetingController;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Database\MeetingRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function sprintf;

final class MeetingControllerTest extends DatabaseTestCase
{
    public function testTheFormIsToldWhichNumberComesNext(): void
    {
        $this->authenticateSecretary();
        $request = $this->pushRequest();

        $response = self::getContainer()->get(MeetingController::class)->create($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        $latest = self::getContainer()->get(MeetingRepository::class)->findOneBy(
            ['type' => MeetingTypes::BV],
            ['number' => 'DESC'],
        );

        self::assertNotNull($latest);
        self::assertStringContainsString(
            'data-controller="meeting-number"',
            $content,
        );
        self::assertStringContainsString(
            sprintf(
                '&quot;BV&quot;:{&quot;name&quot;:&quot;BM&quot;,&quot;next&quot;:%d,&quot;latest&quot;:%d,',
                $latest->getNumber() + 1,
                $latest->getNumber(),
            ),
            $content,
        );
    }

    private function pushRequest(): Request
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function authenticateSecretary(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8002);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a user that keeps the register.',
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [
                    UserRoles::Board->value,
                    UserRoles::DatabaseAdmin->value,
                ],
            ),
        );
    }
}
