<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Checker;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\GraduateConversionLink;
use App\Repository\Checker\MemberRepository as CheckerMemberRepository;
use App\Service\Checker\Renewal as RenewalService;
use App\Service\Database\ActionLinkService;
use App\Tests\Integration\DatabaseTestCase;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

use function preg_match;

#[CoversClass(RenewalService::class)]
#[CoversClass(CheckerMemberRepository::class)]
final class GraduateConversionSweepTest extends DatabaseTestCase
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

    public function testWritesToAMemberWhoseMembershipIsAboutToRunOut(): void
    {
        self::getContainer()->get(RenewalService::class)->sendGraduateConversions();

        $link = $this->ledger->getRepository(GraduateConversionLink::class)->findOneBy([]);
        self::assertInstanceOf(
            GraduateConversionLink::class,
            $link,
            'The seed is expected to contain a member whose membership is about to run out.',
        );

        $member = $link->getMember();
        $email = $this->emailTo((string) $member->getEmail());

        self::assertNotNull(
            $email,
            'The member the offer was recorded for is the member it was sent to.',
        );

        $body = (string) $email->getHtmlBody();

        // The link is the whole of it: without one that resolves, the message is an announcement nobody can act on.
        self::assertSame(
            1,
            preg_match(
                '{/en/graduate/([0-9a-f]{32}\.[0-9a-f]{96})}',
                $body,
                $matches,
            ),
        );
        self::assertSame(
            $link->getId(),
            self::getContainer()->get(ActionLinkService::class)->resolveGraduateConversion($matches[1])?->getId(),
        );
    }

    public function testDoesNotAskAboutTheSameEndingTwice(): void
    {
        $service = self::getContainer()->get(RenewalService::class);
        $service->sendGraduateConversions();

        $after = $this->ledger->getRepository(GraduateConversionLink::class)->count([]);
        self::assertGreaterThan(
            0,
            $after,
        );

        $service->sendGraduateConversions();

        self::assertSame(
            $after,
            $this->ledger->getRepository(GraduateConversionLink::class)->count([]),
        );
    }

    public function testLeavesAMembershipThatEndedLongAgoAlone(): void
    {
        $found = self::getContainer()->get(CheckerMemberRepository::class)->getExpiringConversions();

        foreach ($found as $member) {
            $membership = $member->getCurrentOrLastMembership();
            self::assertNotNull($membership);

            self::assertGreaterThanOrEqual(
                new DateTime()->modify('-' . GraduateConversionLink::GRACE_DAYS . ' days')->setTime(
                    0,
                    0,
                ),
                $membership->getEndDate(),
            );
            self::assertContains(
                $membership->getType(),
                [
                    MembershipTypes::Ordinary,
                    MembershipTypes::External,
                ],
            );
        }
    }

    private function emailTo(string $address): ?Email
    {
        foreach (self::getMailerMessages() as $message) {
            if (!$message instanceof Email) {
                continue;
            }

            foreach ($message->getTo() as $to) {
                if ($to->getAddress() === $address) {
                    return $message;
                }
            }
        }

        return null;
    }
}
