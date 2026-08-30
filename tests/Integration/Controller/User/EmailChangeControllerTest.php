<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\EmailChangeController;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Member as LedgerMember;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Service\Database\Member as MemberService;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function parse_str;
use function parse_url;

use const PHP_URL_QUERY;

#[CoversClass(EmailChangeController::class)]
final class EmailChangeControllerTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get('doctrine')->getManager('default');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->ledger = $ledger;
    }

    public function testFollowingTheLinkAndConfirmingChangesTheAddress(): void
    {
        $member = $this->authenticateMember();
        $link = self::getContainer()->get(MemberService::class)->requestEmailChange(
            $member,
            'confirmed@example.org',
        );
        $token = (string) $link->getPlainToken();

        $request = $this->pushRequest();
        $controller = self::getContainer()->get(EmailChangeController::class);

        $claim = $controller->claim($token);
        self::assertSame(
            Response::HTTP_FOUND,
            $claim->getStatusCode(),
        );

        $controller->confirm($this->requestWithTempHash(
            $request,
            $claim,
        ));

        // The redirect that follows carries no hash: what says which change is meant is now the session.
        $request->query->remove('th');

        $page = $controller->confirm($request);
        self::assertStringContainsString(
            'confirmed@example.org',
            (string) $page->getContent(),
        );

        $applied = $controller->apply(
            $request,
            $this->currentUser(),
        );
        self::assertSame(
            Response::HTTP_FOUND,
            $applied->getStatusCode(),
        );
        self::assertSame(
            'confirmed@example.org',
            $member->getEmail(),
        );
        self::assertTrue($link->isUsed());
    }

    public function testALinkThatWasUsedIsRefused(): void
    {
        $member = $this->authenticateMember();
        $link = self::getContainer()->get(MemberService::class)->requestEmailChange(
            $member,
            'confirmed@example.org',
        );
        $token = (string) $link->getPlainToken();
        $link->setUsed(true);
        $this->ledger->flush();

        $request = $this->pushRequest();

        $response = self::getContainer()->get(EmailChangeController::class)->claim($token);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'no longer works',
            (string) $response->getContent(),
        );
    }

    public function testAnotherMemberCannotConfirmSomebodyElsesChange(): void
    {
        $member = $this->authenticateMember();
        $link = self::getContainer()->get(MemberService::class)->requestEmailChange(
            $member,
            'confirmed@example.org',
        );

        $request = $this->pushRequest();
        $controller = self::getContainer()->get(EmailChangeController::class);
        $controller->confirm($this->requestWithTempHash(
            $request,
            $controller->claim((string) $link->getPlainToken()),
        ));

        $this->authenticateOther($member);

        $response = $controller->apply(
            $request,
            $this->currentUser(),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertFalse($link->isUsed());
        self::assertNotSame(
            'confirmed@example.org',
            $member->getEmail(),
        );
    }

    private function requestWithTempHash(
        Request $request,
        Response $claim,
    ): Request {
        self::assertInstanceOf(
            RedirectResponse::class,
            $claim,
        );

        $query = [];
        parse_str(
            (string) parse_url(
                $claim->getTargetUrl(),
                PHP_URL_QUERY,
            ),
            $query,
        );

        $request->query->set(
            'th',
            $query['th'] ?? '',
        );

        return $request;
    }

    private function currentUser(): User
    {
        $user = self::getContainer()->get('security.token_storage')->getToken()?->getUser();
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function authenticateMember(): LedgerMember
    {
        $subscription = $this->ledger->getRepository(MailingListMember::class)->findOneBy(['toBeDeleted' => false]);
        self::assertInstanceOf(
            MailingListMember::class,
            $subscription,
        );

        $member = $subscription->getMember();
        self::assertInstanceOf(
            LedgerMember::class,
            $member,
        );

        $this->authenticate($member->getLidnr());

        return $member;
    }

    private function authenticateOther(LedgerMember $member): void
    {
        $other = $this->ledger->getRepository(LedgerMember::class)->createQueryBuilder('m')
            ->where('m.lidnr != :lidnr')
            ->setParameter(
                'lidnr',
                $member->getLidnr(),
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();

        $this->authenticate($other->getLidnr());
    }

    private function authenticate(int $lidnr): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [UserRoles::User->value],
            ),
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
}
