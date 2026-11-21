<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\OrganController;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class OrganControllerTest extends DatabaseTestCase
{
    public function testTheSearchOffersBodiesByAbbreviation(): void
    {
        $this->authenticate();

        $response = self::getContainer()->get(OrganController::class)->search(Request::create(
            '/en/bodies/search',
            'GET',
            ['q' => 'BAC'],
        ));

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'BAC',
            (string) $response->getContent(),
        );
    }

    public function testAnAddressThatFoundedNothingIsNotFound(): void
    {
        $this->authenticate();

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(OrganController::class)->view(
            MeetingTypes::BV,
            999,
            2,
            1,
            1,
        );
    }

    public function testTheInfoEndpointListsTheMembersOfTheBody(): void
    {
        $this->authenticate();

        $response = self::getContainer()->get(OrganController::class)->info(
            MeetingTypes::BV,
            142,
            2,
            1,
            1,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'BAC',
            (string) $response->getContent(),
        );
    }

    public function testInfoForAnUnknownBodyIsNotFound(): void
    {
        $this->authenticate();

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(OrganController::class)->info(
            MeetingTypes::BV,
            999,
            2,
            1,
            1,
        );
    }

    private function authenticate(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [
                    UserRoles::Admin->value,
                    UserRoles::DatabaseAdmin->value,
                ],
            ),
        );

        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
