<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Database;

use App\Command\Database\PruneAuditEntriesCommand;
use App\Entity\Database\AuditEmailChange;
use App\Entity\Database\AuditMailingListMembership;
use App\Entity\Database\Enums\MailingListMemberAction;
use App\Entity\Database\Enums\MailingListMemberOrigin;
use App\Entity\Database\MailingList;
use App\Repository\Database\AuditEntryRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PruneAuditEntriesCommand::class)]
final class PruneAuditEntriesCommandTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;
    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->build = new LedgerBuilder($this->ledger);
    }

    /**
     * Personal data in an audit entry is kept for a year and a half, then forgotten. The entry itself
     * stays, preserving the audit trail of a change having happened.
     */
    public function testPersonalDataIsAnonymizedAfterRetentionPeriod(): void
    {
        $member = $this->build->member();

        // Create a mailing list for the test.
        $mailingList = new MailingList();
        $mailingList->name = 'test-list';
        $mailingList->setNlDescription('Test');
        $mailingList->setEnDescription('Test');
        $mailingList->onForm = false;
        $mailingList->defaultSub = false;
        $this->ledger->persist($mailingList);

        // Create an email change audit entry with personal data.
        $emailChange = AuditEmailChange::create(
            $member,
            'old@example.org',
            'new@example.org',
        );
        $this->ledger->persist($emailChange);

        // Create a mailing list membership audit entry with personal data.
        $membershipAudit = AuditMailingListMembership::create(
            MailingListMemberAction::Add,
            MailingListMemberOrigin::Manual,
            $member,
            $mailingList,
            'test@example.org',
        );
        $this->ledger->persist($membershipAudit);
        $this->ledger->flush();

        $emailChangeId = $emailChange->getId();
        $membershipAuditId = $membershipAudit->getId();

        // Run the anonymization with a date in the future so both entries are anonymized.
        $repository = self::getContainer()->get(AuditEntryRepository::class);
        $anonymized = $repository->anonymizePersonalDataEntriesOccurredBefore(
            new DateTimeImmutable('+1 day'),
        );

        $this->ledger->clear();

        // Reload the entries.
        $emailChange = $this->ledger->find(
            AuditEmailChange::class,
            $emailChangeId,
        );
        $membershipAudit = $this->ledger->find(
            AuditMailingListMembership::class,
            $membershipAuditId,
        );

        self::assertGreaterThanOrEqual(
            2,
            $anonymized,
        );

        // Both entries should have their personal data cleared.
        self::assertNotNull($emailChange);
        self::assertNull($emailChange->oldEmail);
        self::assertNull($emailChange->newEmail);

        self::assertNotNull($membershipAudit);
        self::assertNull($membershipAudit->email);
    }
}
