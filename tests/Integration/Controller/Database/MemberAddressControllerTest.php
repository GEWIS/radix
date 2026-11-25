<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\MemberAddressController;
use App\Entity\Database\Address;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\PostalRegions;
use App\Entity\Database\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Database\MemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function strtolower;

/**
 * The three addresses of one member. Which address a page is about follows from the route, so adding one that does not
 * exist yet, editing the one that does, and deleting it are three separate paths. Invoked directly, mirroring the rest
 * of the suite.
 */
final class MemberAddressControllerTest extends DatabaseTestCase
{
    /** A member whose only seeded address is their home address. */
    private const int MEMBER = 8030;

    public function testAddingAnAddressPersistsItAndReturnsToTheMember(): void
    {
        $this->authenticate();
        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'country' => PostalRegions::Netherlands->value,
            'street' => 'Studieweg',
            'number' => '12',
            'postalCode' => '5612 AZ',
            'city' => 'Eindhoven',
            'phone' => '0401234567',
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(MemberAddressController::class)->add(
            $request,
            self::MEMBER,
            AddressTypes::Student,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/admin/members/' . self::MEMBER,
            self::redirectTarget($response),
        );

        $address = $this->memberRepository()->findMemberAddress(
            $this->member(),
            AddressTypes::Student,
        );
        self::assertNotNull(
            $address,
            'The submitted address is expected to be stored.',
        );
        self::assertSame(
            'Studieweg',
            $address->street,
        );
    }

    public function testAnIncompleteAddressIsRejectedOnItsFields(): void
    {
        $this->authenticate();
        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'street' => 'A',
            'number' => '0',
            'postalCode' => '',
            'city' => '',
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(MemberAddressController::class)->add(
            $request,
            self::MEMBER,
            AddressTypes::Student,
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertNull($this->memberRepository()->findMemberAddress(
            $this->member(),
            AddressTypes::Student,
        ));
    }

    public function testEditingTheHomeAddressReplacesItsStreet(): void
    {
        $this->authenticate();
        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'country' => PostalRegions::Netherlands->value,
            'street' => 'Nieuwestraat',
            'number' => '31',
            'postalCode' => '5630 WO',
            'city' => 'Eindhoven',
            'phone' => '1',
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(MemberAddressController::class)->edit(
            $request,
            self::MEMBER,
            AddressTypes::Home,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            'Nieuwestraat',
            $this->homeAddress()->street,
        );
    }

    public function testEditingAnAddressThatIsNotOnFileIsNotFound(): void
    {
        $this->authenticate();
        $request = $this->postRequest([]);
        $this->pushRequest($request);

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(MemberAddressController::class)->edit(
            $request,
            self::MEMBER,
            AddressTypes::Mail,
        );
    }

    public function testRemovingTheHomeAddressDeletesIt(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberAddressController::class)->remove(
            self::MEMBER,
            AddressTypes::Home,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNull($this->memberRepository()->findMemberAddress(
            $this->member(),
            AddressTypes::Home,
        ));
    }

    public function testRemovingAnAddressThatIsNotOnFileIsNotFound(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(MemberAddressController::class)->remove(
            self::MEMBER,
            AddressTypes::Mail,
        );
    }

    public function testADeletedMembersAddressesAreReplacedByTheNotice(): void
    {
        $this->authenticate();
        $request = $this->postRequest([]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(MemberAddressController::class)->add(
            $request,
            8205,
            AddressTypes::Student,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'deleted',
            strtolower((string) $response->getContent()),
        );
    }

    private function homeAddress(): Address
    {
        $address = $this->memberRepository()->findMemberAddress(
            $this->member(),
            AddressTypes::Home,
        );
        self::assertNotNull(
            $address,
            'The seed is expected to give the member a home address.',
        );

        return $address;
    }

    private function member(): Member
    {
        $member = $this->memberRepository()->find(self::MEMBER);
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }

    private function memberRepository(): MemberRepository
    {
        return self::getContainer()->get(MemberRepository::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postRequest(array $body): Request
    {
        $request = Request::create(
            '/en/admin/members/' . self::MEMBER,
            'POST',
            ['address_edit' => $body],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        return $request;
    }

    private function pushRequest(Request $request): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    private function authenticate(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8002);
        self::assertInstanceOf(
            User::class,
            $user,
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
