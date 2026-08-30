<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\ProspectiveMemberController;
use App\Entity\Database\Enums\GraduateConversionOutcome;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\GraduateConversionLink;
use App\Entity\Database\Member;
use App\Service\Database\ActionLinkService;
use App\Service\Database\Member as MemberService;
use App\Tests\Integration\DatabaseTestCase;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function array_diff;
use function array_values;
use function parse_str;
use function parse_url;

use const PHP_URL_QUERY;

#[CoversClass(ProspectiveMemberController::class)]
final class GraduateConversionTest extends DatabaseTestCase
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

    public function testAcceptingWritesAGraduateMembership(): void
    {
        $member = $this->expiringMember();
        $link = $this->link($member);
        $controller = self::getContainer()->get(ProspectiveMemberController::class);

        $request = $this->pushRequest();
        $this->carryTempHash(
            $request,
            $controller->graduateClaim((string) $link->getPlainToken()),
        );
        $controller->graduate($request);
        $request->query->remove('th');

        $submission = $this->submission(
            $member,
            'accept',
        );
        $this->pushRequest($submission);

        $response = $controller->graduate($submission);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            MembershipTypes::Graduate,
            $member->getCurrentOrLastMembership()?->getType(),
        );
        self::assertSame(
            GraduateConversionOutcome::Accepted,
            $link->getOutcome(),
        );
        self::assertTrue($link->isUsed());
    }

    public function testDecliningRecordsTheAnswerAndLeavesTheMembershipAlone(): void
    {
        $member = $this->expiringMember();
        $before = $member->getCurrentOrLastMembership()?->getType();
        $link = $this->link($member);
        $controller = self::getContainer()->get(ProspectiveMemberController::class);

        $request = $this->pushRequest();
        $this->carryTempHash(
            $request,
            $controller->graduateClaim((string) $link->getPlainToken()),
        );
        $controller->graduate($request);

        $submission = $this->submission(
            $member,
            'decline',
        );
        $this->pushRequest($submission);

        $controller->graduate($submission);

        self::assertSame(
            GraduateConversionOutcome::Declined,
            $link->getOutcome(),
        );
        self::assertSame(
            $before,
            $member->getCurrentOrLastMembership()?->getType(),
        );
    }

    public function testTheSecretarySettlingTheEndingRetiresTheOffer(): void
    {
        $member = $this->expiringMember();
        $link = $this->link($member);

        self::getContainer()->get(MemberService::class)->bulkRenewal(
            (string) $member->getLidnr(),
            MembershipTypes::Graduate,
            true,
        );

        self::assertSame(
            GraduateConversionOutcome::Superseded,
            $link->getOutcome(),
        );
        self::assertTrue($link->isUsed());
        self::assertNull(
            self::getContainer()->get(ActionLinkService::class)->resolveGraduateConversion(
                (string) $link->getPlainToken(),
            ),
            'A link whose ending has been settled writes no second membership.',
        );
    }

    /**
     * The batch is for the members the sweep cannot settle: whoever it has asked keeps their window.
     */
    public function testTheBulkShortcutLeavesOutAMemberWhoWasAsked(): void
    {
        $service = self::getContainer()->get(MemberService::class);
        $before = $service->getMembersRequiringAttention()['bulk_renewal_shortcuts']['expiring_non_active'];

        self::assertNotEmpty(
            $before,
            'The seed is expected to contain an expiring member the secretary could convert.',
        );

        $member = $this->ledger->getRepository(Member::class)->find($before[0]);
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        $this->link($member);

        $after = $service->getMembersRequiringAttention()['bulk_renewal_shortcuts']['expiring_non_active'];

        self::assertNotContains(
            $member->getLidnr(),
            $after,
        );
        self::assertSame(
            [$member->getLidnr()],
            array_values(array_diff($before, $after)),
            'Only the member who was asked drops out of the batch.',
        );
    }

    public function testALinkThatWasAnsweredIsRefused(): void
    {
        $member = $this->expiringMember();
        $link = $this->link($member);
        $token = (string) $link->getPlainToken();
        $link->setUsed(true);
        $this->ledger->flush();

        $this->pushRequest();

        $response = self::getContainer()->get(ProspectiveMemberController::class)->graduateClaim($token);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'no longer works',
            (string) $response->getContent(),
        );
    }

    /**
     * @return Request the submission, with the fields the form binds to the member
     */
    private function submission(
        Member $member,
        string $button,
    ): Request {
        $request = new Request(
            request: [
                'member_graduate_conversion' => [
                    'email' => (string) $member->getEmail(),
                    'supremum' => '1',
                    $button => '',
                    '_csrf_token' => 'csrf-token',
                ],
            ],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);

        return $request;
    }

    private function carryTempHash(
        Request $request,
        Response $claim,
    ): void {
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
    }

    private function link(Member $member): GraduateConversionLink
    {
        $membership = $member->getCurrentOrLastMembership();
        self::assertNotNull($membership);

        $link = new GraduateConversionLink(
            $member,
            DateTime::createFromInterface($membership->getEndDate()),
        );
        $this->ledger->persist($link);
        $this->ledger->flush();

        return $link;
    }

    private function expiringMember(): Member
    {
        $member = $this->ledger->getRepository(Member::class)->createQueryBuilder('m')
            ->join(
                'm.memberships',
                'mem',
            )
            ->where('mem.type = :ordinary')
            ->andWhere('m.email IS NOT NULL')
            ->andWhere('m.deleted = false')
            ->setParameter(
                'ordinary',
                MembershipTypes::Ordinary,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(
            Member::class,
            $member,
            'The seed is expected to contain an ordinary member.',
        );

        return $member;
    }

    /**
     * Shared by both stages: the first binds the link to it, the second reads it back.
     */
    private ?SessionInterface $session = null;

    private function pushRequest(?Request $request = null): Request
    {
        if (null === $this->session) {
            $session = self::getContainer()->get('session.factory')->createSession();
            self::assertInstanceOf(
                FlashBagAwareSessionInterface::class,
                $session,
            );

            $this->session = $session;
        }

        $request ??= new Request();
        $request->setLocale('en');
        $request->setSession($this->session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }
}
