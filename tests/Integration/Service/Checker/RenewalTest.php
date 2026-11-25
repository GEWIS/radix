<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Checker;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member;
use App\Entity\Database\RenewalLink;
use App\Repository\Checker\MemberRepository as CheckerMemberRepository;
use App\Repository\Database\ActionLinkRepository;
use App\Repository\Decision\MemberRepository as DecisionMemberRepository;
use App\Service\Application\Email;
use App\Service\Checker\Renewal;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_values;
use function sprintf;

/**
 * The graduate renewal run: who is mailed a link, what the link says, and what the mail names when it succeeds. The
 * graduate it writes to ends their membership within the window; everybody else is left in the register alone.
 */
#[CoversClass(Renewal::class)]
// The mail service is stood in for; nothing about how it sends is being asserted.
#[AllowMockObjectsWithoutExpectations]
final class RenewalTest extends DatabaseTestCase
{
    private LedgerBuilder $build;

    /** @var list<array{recipient: Address, subject: string, template: string, context: array<string, mixed>, bcc: bool}> */
    private array $sent = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager('database');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $entityManager,
        );

        $this->entityManager = $entityManager;
        $this->build = new LedgerBuilder($this->entityManager);

        // The run mails at most ten graduates an hour, so the seed's own graduates would otherwise take every place
        // before the one this test builds is reached. Hiding them is the fixture's own way of saying "do not write to
        // this member" rather than deleting anything from the register.
        $checker = self::getContainer()->get(CheckerMemberRepository::class);
        foreach ($checker->getExpiringGraduates(new DateTimeImmutable('+45 days')) as $due) {
            $due->hidden = true;
        }

        $this->entityManager->flush();
    }

    public function testAnExpiringGraduateIsMailedALinkForTheComingYear(): void
    {
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+20 days',
        );

        $this->renewal()->sendRenewalGraduates();

        $links = $this->links($graduate);
        self::assertCount(
            1,
            $links,
        );

        $link = $links[0];
        self::assertNotNull(
            $link->plainToken,
            'The link is mailed with the token it was generated with; only the hash is stored.',
        );
        self::assertGreaterThan(
            $link->currentExpiration,
            $link->newExpiration,
        );

        self::assertCount(
            1,
            $this->sent,
        );
        [$mail] = $this->sent;

        self::assertSame(
            sprintf(
                'Graduate Renewal (%d)',
                $graduate->lidnr,
            ),
            $mail['subject'],
        );
        self::assertSame(
            'database/email/graduate-renewal.html.twig',
            $mail['template'],
        );
        self::assertSame(
            $graduate->firstName,
            $mail['context']['firstName'],
        );
        self::assertSame(
            'https://example.test/renew',
            $mail['context']['url'],
        );
        self::assertSame(
            $link->newExpiration,
            $mail['context']['newExpiration'],
        );
        self::assertFalse(
            $mail['context']['isInstalled'],
            'A graduate who holds no organ membership is not reminded of one.',
        );
        self::assertTrue(
            $mail['bcc'],
            'The secretary keeps a copy of the renewal mail.',
        );
    }

    public function testAGraduateBeyondTheWindowIsNotWrittenTo(): void
    {
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+2 months',
        );

        $this->renewal()->sendRenewalGraduates();

        self::assertSame(
            [],
            $this->links($graduate),
            'The run reaches 45 days ahead and no further.',
        );
    }

    public function testTheRunDoesNotWriteASecondLinkToTheSameGraduate(): void
    {
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+20 days',
        );

        $renewal = $this->renewal();
        $renewal->sendRenewalGraduates();
        $renewal->sendRenewalGraduates();

        self::assertCount(
            1,
            $this->links($graduate),
            'Once a link exists for this expiration, the graduate drops out of the run.',
        );
    }

    public function testTheSuccessMailNamesTheOldAndTheNewExpiration(): void
    {
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+20 days',
        );

        $link = self::getContainer()->get(ActionLinkRepository::class)->createRenewalByMember($graduate);
        self::assertInstanceOf(
            RenewalLink::class,
            $link,
        );

        $this->renewal()->sendRenewalSuccessEmail($link);

        self::assertCount(
            1,
            $this->sent,
        );
        [$mail] = $this->sent;

        self::assertSame(
            'database/email/graduate-renewal-success.html.twig',
            $mail['template'],
        );
        self::assertSame(
            $link->currentExpiration,
            $mail['context']['oldExpiration'],
        );
        self::assertSame(
            $link->newExpiration,
            $mail['context']['newExpiration'],
        );
        self::assertTrue($mail['bcc']);
    }

    private function renewal(): Renewal
    {
        $container = self::getContainer();

        $email = $this->createMock(Email::class);
        $email->method('secretary')->willReturn(new Address(
            'secretary@example.org',
            'Secretary',
        ));
        $email->method('send')->willReturnCallback(
            function (
                Address $recipient,
                string $subject,
                string $template,
                array $context = [],
                ?Address $replyTo = null,
                bool $bccReplyTo = false,
            ): void {
                $this->sent[] = [
                    'recipient' => $recipient,
                    'subject' => $subject,
                    'template' => $template,
                    'context' => $context,
                    'bcc' => $bccReplyTo,
                ];
            },
        );

        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.test/renew');

        return new Renewal(
            $container->get(ActionLinkRepository::class),
            $container->get(CheckerMemberRepository::class),
            $container->get(DecisionMemberRepository::class),
            $email,
            $urlGenerator,
        );
    }

    /**
     * @return list<RenewalLink>
     */
    private function links(Member $member): array
    {
        $links = self::getContainer()->get(ActionLinkRepository::class)->findRenewalByMember($member->lidnr);

        return array_values($links ?? []);
    }
}
