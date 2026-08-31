<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\MemberDetailsController;
use App\DataFixtures\Member\MemberPopulationFixture;
use App\Entity\Database\Address;
use App\Entity\Database\EmailChangeLink;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\MailingList;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Member as LedgerMember;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function in_array;

#[CoversClass(MemberDetailsController::class)]
final class MemberDetailsControllerTest extends DatabaseTestCase
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

    public function testShowsWhatAMemberMayChangeAndWhatTheSecretaryKeeps(): void
    {
        $member = $this->authenticateMember();
        $this->pushRequest();

        $response = $this->render();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();

        self::assertStringContainsString(
            (string) $member->getEmail(),
            $content,
        );
        self::assertStringContainsString(
            (string) $member->getStudentNumber(),
            $content,
        );

        // The years they were with the association, which is the record the secretary keeps and the member is shown.
        self::assertNotCount(
            0,
            $member->getMemberships(),
        );

        foreach ($member->getMemberships() as $membership) {
            self::assertStringContainsString(
                $membership->getStartDate()->format('Y'),
                $content,
            );
        }

        foreach ($this->ledger->getRepository(MailingList::class)->findAll() as $list) {
            if ($list->getSelfService()) {
                self::assertStringContainsString(
                    $list->getName(),
                    $content,
                );
                self::assertStringContainsString(
                    $list->getEnDescription(),
                    $content,
                );

                continue;
            }

            self::assertStringNotContainsString(
                'value="' . $list->getName() . '"',
                $content,
            );
        }

        // The panel heading says what these are, so the field adds no legend of its own.
        self::assertStringNotContainsString(
            '<legend',
            $content,
        );
    }

    public function testAskingForAnotherAddressLeavesTheMemberAlone(): void
    {
        $member = $this->authenticateMember();
        $before = $member->getEmail();

        $request = new Request(
            request: [
                'member_email_change' => [
                    'email' => 'somewhere-else@example.org',
                    '_csrf_token' => 'csrf-token',
                ],
            ],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);
        $this->pushRequest($request);

        $response = $this->render($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            $before,
            $member->getEmail(),
        );

        $link = $this->ledger->getRepository(EmailChangeLink::class)->findOneBy(['member' => $member->getLidnr()]);
        self::assertInstanceOf(
            EmailChangeLink::class,
            $link,
        );
        self::assertSame(
            'somewhere-else@example.org',
            $link->getNewEmail(),
        );
        self::assertSame(
            $before,
            $link->getPreviousEmail(),
        );
    }

    public function testRefusesAnAddressThatBelongsToSomebodyElse(): void
    {
        $member = $this->authenticateMember();
        $someoneElse = $this->ledger->getRepository(LedgerMember::class)->createQueryBuilder('m')
            ->where('m.email IS NOT NULL')
            ->andWhere('m.lidnr != :lidnr')
            ->setParameter(
                'lidnr',
                $member->getLidnr(),
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();

        $request = new Request(
            request: [
                'member_email_change' => [
                    'email' => (string) $someoneElse->getEmail(),
                    '_csrf_token' => 'csrf-token',
                ],
            ],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);
        $this->pushRequest($request);

        $response = $this->render($request);

        // The page is rendered again with what is wrong with it, which is what a form that did not validate answers.
        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertEmpty(
            $this->ledger->getRepository(EmailChangeLink::class)->findBy(['member' => $member->getLidnr()]),
        );
    }

    public function testASubmissionOnlyTouchesTheListsThatWereOffered(): void
    {
        // Synced subscriptions: one still waiting to be carried across is locked by the form.
        $member = $this->authenticate(MemberPopulationFixture::ADMIN);
        $offered = [];
        foreach ($this->ledger->getRepository(MailingList::class)->findAll() as $list) {
            if (!$list->getSelfService()) {
                continue;
            }

            $offered[] = $list->getName();
        }

        self::assertNotEmpty(
            $offered,
            'The seed is expected to contain a list a member may manage themselves.',
        );

        $before = [];
        foreach ($member->getMailingListMemberships() as $subscription) {
            $before[$subscription->getMailingList()->getName()] = $subscription->isToBeDeleted();
        }

        $request = new Request(
            request: [
                'member_lists' => [
                    'lists' => [],
                    '_csrf_token' => 'csrf-token',
                ],
            ],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);
        $this->pushRequest($request);

        $this->render($request);

        foreach ($member->getMailingListMemberships() as $subscription) {
            $name = $subscription->getMailingList()->getName();

            if (
                in_array(
                    $name,
                    $offered,
                    true,
                )
            ) {
                self::assertTrue(
                    $subscription->isToBeDeleted(),
                    'A list that was offered and left unticked is unsubscribed.',
                );

                continue;
            }

            self::assertSame(
                $before[$name] ?? false,
                $subscription->isToBeDeleted(),
                'A list that was never offered is left exactly as it was.',
            );
        }
    }

    public function testOffersAnAddressToAddAndOneToCorrect(): void
    {
        $member = $this->authenticateMemberWithAnAddress();
        $this->pushRequest();
        $controller = self::getContainer()->get(MemberDetailsController::class);
        $user = $this->currentUser();

        foreach (AddressTypes::cases() as $type) {
            $response = $controller->address(
                new Request(),
                $type,
                $user,
            );

            self::assertSame(
                Response::HTTP_OK,
                $response->getStatusCode(),
            );
            self::assertStringContainsString(
                '/user/settings/details',
                (string) $response->getContent(),
                'The page offers a way back to the details it belongs to.',
            );
        }

        // Removing is offered for an address that is on file, and is nothing to offer for one that is not.
        $onFile = $member->getAddresses()->first();
        self::assertInstanceOf(
            Address::class,
            $onFile,
        );

        $response = $controller->removeAddress(
            new Request(),
            $onFile->getType(),
            $user,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
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

    private function render(?Request $request = null): Response
    {
        $user = self::getContainer()->get('security.token_storage')->getToken()?->getUser();
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return self::getContainer()->get(MemberDetailsController::class)->index(
            $request ?? new Request(),
            $user,
        );
    }

    private function authenticateMemberWithAnAddress(): LedgerMember
    {
        $address = $this->ledger->getRepository(Address::class)->createQueryBuilder('a')
            ->join(
                'a.member',
                'm',
            )
            ->andWhere('m.deleted = false')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(
            Address::class,
            $address,
            'The seed is expected to contain a member with an address.',
        );

        $member = $address->getMember();
        self::assertInstanceOf(
            LedgerMember::class,
            $member,
        );

        $user = $this->entityManager->getRepository(User::class)->find($member->getLidnr());
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

        return $member;
    }

    private function authenticate(int $lidnr): LedgerMember
    {
        $member = $this->ledger->getRepository(LedgerMember::class)->find($lidnr);
        self::assertInstanceOf(
            LedgerMember::class,
            $member,
        );

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

        return $member;
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

        $user = $this->entityManager->getRepository(User::class)->find($member->getLidnr());
        self::assertInstanceOf(
            User::class,
            $user,
            'Every seeded member has an account.',
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [UserRoles::User->value],
            ),
        );

        return $member;
    }

    private function pushRequest(?Request $request = null): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request ??= new Request();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
