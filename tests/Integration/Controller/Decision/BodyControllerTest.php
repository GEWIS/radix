<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\BodyController;
use App\Entity\User\CompanyUser;
use App\Entity\User\User;
use App\Repository\User\CompanyUserRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function str_contains;

/**
 * Who is shown a body's roll call. The page itself is public, so the members panel is what the account decides: a
 * member sees it and everybody else is offered the login instead.
 *
 * The page used to ask for `IS_AUTHENTICATED_FULLY`, which a remember-me session does not hold, so a member who had
 * not typed their password this session was told to sign in while already signed in (GH-125). Asking for the role
 * instead is also what keeps a company representative out, which full authentication never did.
 */
final class BodyControllerTest extends DatabaseTestCase
{
    private const string BODY_ABBR = 'GETÉST';

    public function testAMemberOnARememberMeSessionSeesTheMembers(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8030);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a user for the member.',
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new RememberMeToken(
                $user,
                'main',
            ),
        );
        $this->pushRequest();

        $content = $this->render();

        self::assertStringContainsString(
            'id="organ-members"',
            $content,
        );
        self::assertStringContainsString(
            'BÖDY Smits',
            $content,
        );
    }

    public function testAVisitorWithoutAnAccountIsOfferedTheLogin(): void
    {
        $this->pushRequest();

        $content = $this->render();

        self::assertStringNotContainsString(
            'id="organ-members"',
            $content,
        );
        self::assertStringNotContainsString(
            'BÖDY Smits',
            $content,
        );
    }

    /**
     * A representative signs in on their own firewall and is a fully authenticated user like any other, so the old
     * check let them read the roll call. The names of a body's members are not theirs to read.
     */
    public function testACompanyRepresentativeIsNotShownTheMembers(): void
    {
        $companyUser = self::getContainer()->get(CompanyUserRepository::class)
            ->loadUserByIdentifier('recruitment@nexunt.example.com');
        self::assertInstanceOf(
            CompanyUser::class,
            $companyUser,
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $companyUser,
                'company',
                $companyUser->getRoles(),
            ),
        );
        $this->pushRequest();

        $content = $this->render();

        self::assertStringNotContainsString(
            'id="organ-members"',
            $content,
        );
        self::assertStringNotContainsString(
            'BÖDY Smits',
            $content,
        );
    }

    private function render(): string
    {
        $response = $this->controller()->body(
            'committee',
            self::BODY_ABBR,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        self::assertTrue(
            str_contains(
                $content,
                self::BODY_ABBR,
            ),
            'The body page is expected to render the body it was asked for.',
        );

        return $content;
    }

    private function pushRequest(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    private function controller(): BodyController
    {
        return self::getContainer()->get(BodyController::class);
    }
}
