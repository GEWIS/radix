<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Checker;

use App\Entity\Database\Member;
use App\Service\Checker\Membership as MembershipService;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

use function implode;

#[CoversClass(MembershipService::class)]
final class MembershipConsistencyTest extends DatabaseTestCase
{
    use MailerAssertionsTrait;

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

    public function testTheSeededRegisterHoldsTogether(): void
    {
        $service = self::getContainer()->get(MembershipService::class);
        $problems = [];

        foreach ($this->ledger->getRepository(Member::class)->findAll() as $member) {
            foreach ($service->problemsOf($member) as $problem) {
                $problems[] = $problem->asText();
            }
        }

        self::assertSame(
            [],
            $problems,
            "The seed writes memberships that overlap or run backwards:\n" . implode(
                "\n",
                $problems,
            ),
        );
    }

    public function testWritesToTheSecretaryEvenWhenNothingIsWrong(): void
    {
        self::getContainer()->get(MembershipService::class)->check();

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(
            Email::class,
            $email,
        );

        self::assertSame(
            'Membership Checker Report',
            $email->getSubject(),
        );
        self::assertStringContainsString(
            'run in order and do not overlap',
            (string) $email->getTextBody(),
        );
    }
}
