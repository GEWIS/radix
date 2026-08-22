<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

use function sprintf;

/**
 * The reference table below is one row per table, which is what makes it readable at all.
 *
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 * phpcs:disable Squiz.Arrays.ArrayDeclaration.SingleLineNotAllowed
 */
final class Version20260820151122 extends AbstractMigration
{
    /**
     * The four tables that hold one record per sub-decision, each with the guard it is missing and with the plain
     * index and the foreign key that stand where that guard belongs.
     */
    private const array REFERENCE_TABLES = [
        'Organ' => ['foundation_uniq', 'IDX_46C39B8EEFBA85FF292FAD512F37B76A76CE1878B79BB36', 'FK_46C39B8EEFBA85FF292FAD512F37B76A76CE1878B79BB36'],
        'OrganMember' => ['installation_uniq', 'IDX_E5CB2C7DEFBA85FF292FAD512F37B76A76CE1878B79BB36', 'FK_E5CB2C7DEFBA85FF292FAD512F37B76A76CE1878B79BB36'],
        'BoardMember' => ['installationDec_uniq', 'IDX_D9517B2EEFBA85FF292FAD512F37B76A76CE1878B79BB36', 'FK_D9517B2EEFBA85FF292FAD512F37B76A76CE1878B79BB36'],
        'Keyholder' => ['grantingDec_uniq', 'IDX_3C5F7B4DEFBA85FF292FAD512F37B76A76CE1878B79BB36', 'FK_3C5F7B4DEFBA85FF292FAD512F37B76A76CE1878B79BB36'],
    ];

    /** The five columns that spell out which sub-decision a record was made by. */
    private const string REFERENCE_COLUMNS = 'r_meeting_type, r_meeting_number, r_decision_point, r_decision_number, r_sequence';

    public function getDescription(): string
    {
        return 'Make the tables the decisions are written out into say what the code that writes them believes. Who is on a mailing list is identified by the list and the address, not by the list, the member and the address together: a subscription is looked up by exactly those two, and with the member still in the key that lookup could never find one, so every run wrote another copy instead of updating the row that was already there. Where the same address sits on the same list twice under two different members, only the first of them survives; the address is the thing subscribed and it can be on a list once, so the other row was always the same subscription written down twice. The three-column index that used to stand guard goes with it, being weaker than the new key in every case. Four more guards come back, over the record of a body, of somebody in a body, of a board member and of a keyholder. Each of those records is made by exactly one sub-decision and there may only ever be one of them per sub-decision; that is what stops a second copy appearing every time the decisions are read out again. They were dropped when the tables were converted to the new collation and never put back. Anything that slipped through in the meantime is merged into one record of it, the one whose page is published where a copy has one and the first of them otherwise: for a body that means its activities and the drafts and proposals behind them, the people in it, its photo tags, the decisions listed against it, the standing and per-period limits on how many activities it may propose, and its page all follow the record that stays. Where that record already holds the same thing — the same decision listed against it, a standing limit of its own or one for the same period, the same photo tagged with it — the row on the copy has nowhere to go and is removed instead of moved: it is that same thing written down a second time, and the record that stays already says it. And where the copy carried a page of its own as well, that page goes, and with it the history it kept, the remarks left on it and the links it pointed to, because a body has one page — and the published one is what settles which of the two that is. Where neither copy has a published page nothing tells them apart, and the record written first is the one kept. Lastly, the abbreviation of the body a regulation decision is about moves out of the name column and into the abbreviation column, which is where it is read from now; the name column is emptied on those decisions, as only a founding decision gives a body a name.';
    }

    public function up(Schema $schema): void
    {
        // A subscription is the list plus the address. Where the same pair appears under two members, the lower member
        // number is the one kept; the rows say the same thing, so there is nothing else to choose between them.
        $this->addSql(<<<'SQL'
            DELETE subscription FROM MailingListMember subscription
            INNER JOIN (
                SELECT mailingList, email, MIN(member) AS keep_member
                FROM MailingListMember
                GROUP BY mailingList, email
                HAVING COUNT(*) > 1
            ) duplicate
                ON duplicate.mailingList = subscription.mailingList
                AND duplicate.email = subscription.email
            WHERE subscription.member <> duplicate.keep_member
            SQL);

        // Both foreign-key columns keep an index of their own, so the key can be rebuilt without touching them.
        $this->addSql(<<<'SQL'
            ALTER TABLE MailingListMember
              DROP INDEX mailinglistmember_unique_idx,
              DROP PRIMARY KEY,
              ADD PRIMARY KEY (mailingList, email)
            SQL);

        // A body written out twice is the same body twice, so everything the site hung off the copy belongs to the
        // record that stays and is moved over before the copy goes.
        $this->addSql(<<<'SQL'
            CREATE TABLE organ_duplicate (
              dupe_id INT NOT NULL,
              keep_id INT NOT NULL,
              PRIMARY KEY (dupe_id),
              INDEX organ_duplicate_keep (keep_id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        // A record whose sub-decision is not filled in at all is left alone: it is not a copy of anything, and the
        // guard being added would not have caught it either. Which of the copies stays is decided by their pages,
        // because only one page can be kept and the order the records were written in says nothing about which of them
        // the site has been showing: the copy whose page is published is the one that survives, and the first of them
        // only where no copy has a published page. That is the same choice the pages themselves were merged on, one
        // step earlier. A body has at most one page, so joining it in cannot turn one record into several.
        $this->addSql(<<<'SQL'
            INSERT INTO organ_duplicate (dupe_id, keep_id)
            SELECT organ.id, duplicate.keep_id
            FROM Organ organ
            INNER JOIN (
                SELECT candidate.r_meeting_type,
                       candidate.r_meeting_number,
                       candidate.r_decision_point,
                       candidate.r_decision_number,
                       candidate.r_sequence,
                       COALESCE(
                           MIN(CASE WHEN information.liveRevision_id IS NOT NULL THEN candidate.id END),
                           MIN(candidate.id)
                       ) AS keep_id
                FROM Organ candidate
                LEFT JOIN OrganInformation information ON information.organ_id = candidate.id
                WHERE candidate.r_meeting_type IS NOT NULL
                  AND candidate.r_meeting_number IS NOT NULL
                  AND candidate.r_decision_point IS NOT NULL
                  AND candidate.r_decision_number IS NOT NULL
                  AND candidate.r_sequence IS NOT NULL
                GROUP BY candidate.r_meeting_type,
                         candidate.r_meeting_number,
                         candidate.r_decision_point,
                         candidate.r_decision_number,
                         candidate.r_sequence
                HAVING COUNT(*) > 1
            ) duplicate
                ON duplicate.r_meeting_type = organ.r_meeting_type
                AND duplicate.r_meeting_number = organ.r_meeting_number
                AND duplicate.r_decision_point = organ.r_decision_point
                AND duplicate.r_decision_number = organ.r_decision_number
                AND duplicate.r_sequence = organ.r_sequence
            WHERE organ.id <> duplicate.keep_id
            SQL);

        // Nothing in these can land on top of anything, so it all simply moves across. An activity itself no longer
        // names a body: that moved onto its revisions when they were introduced, and it is reached there.
        foreach (['ActivityRevision', 'ActivityProposal', 'OrganMember'] as $table) {
            $this->addSql(sprintf(
                'UPDATE %1$s target INNER JOIN organ_duplicate duplicate ON duplicate.dupe_id = target.organ_id'
                . ' SET target.organ_id = duplicate.keep_id',
                $table,
            ));
        }

        // These already refuse to hold the same thing twice, so a row that would land on top of one the surviving body
        // has stays where it is and is dropped afterwards: it says the same thing a second time. A photo tagged with
        // both copies of a body is the same tag twice, and one of the two goes the same way.
        foreach (['organs_subdecisions', 'ProposalLimit', 'PeriodProposalLimit', 'OrganInformation', 'Tag'] as $table) {
            $this->addSql(sprintf(
                'UPDATE IGNORE %1$s target INNER JOIN organ_duplicate duplicate ON duplicate.dupe_id = target.organ_id'
                . ' SET target.organ_id = duplicate.keep_id',
                $table,
            ));
        }

        foreach (['organs_subdecisions', 'ProposalLimit', 'PeriodProposalLimit', 'Tag'] as $table) {
            $this->addSql(sprintf(
                'DELETE target FROM %1$s target'
                . ' INNER JOIN organ_duplicate duplicate ON duplicate.dupe_id = target.organ_id',
                $table,
            ));
        }

        // A page that stayed behind is a whole record with a history hanging off it, and it has to come apart from the
        // outside in: the two pointers into the chain first, then the discussion and the links, then the chain itself.
        $this->addSql(<<<'SQL'
            UPDATE OrganInformation information
            INNER JOIN organ_duplicate duplicate ON duplicate.dupe_id = information.organ_id
            SET information.currentRevision_id = NULL,
                information.liveRevision_id = NULL
            SQL);
        $this->addSql(<<<'SQL'
            DELETE comment FROM OrganInformationRevisionComment comment
            INNER JOIN OrganInformationRevision revision ON revision.id = comment.revision_id
            INNER JOIN OrganInformation information ON information.id = revision.organInformation_id
            INNER JOIN organ_duplicate duplicate ON duplicate.dupe_id = information.organ_id
            SQL);
        $this->addSql(<<<'SQL'
            DELETE link FROM OrganSocialLink link
            INNER JOIN OrganInformationRevision revision ON revision.id = link.revision_id
            INNER JOIN OrganInformation information ON information.id = revision.organInformation_id
            INNER JOIN organ_duplicate duplicate ON duplicate.dupe_id = information.organ_id
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE OrganInformationRevision revision
            INNER JOIN OrganInformation information ON information.id = revision.organInformation_id
            INNER JOIN organ_duplicate duplicate ON duplicate.dupe_id = information.organ_id
            SET revision.previousRevision_id = NULL
            SQL);
        $this->addSql(<<<'SQL'
            DELETE revision FROM OrganInformationRevision revision
            INNER JOIN OrganInformation information ON information.id = revision.organInformation_id
            INNER JOIN organ_duplicate duplicate ON duplicate.dupe_id = information.organ_id
            SQL);
        $this->addSql(<<<'SQL'
            DELETE information FROM OrganInformation information
            INNER JOIN organ_duplicate duplicate ON duplicate.dupe_id = information.organ_id
            SQL);

        $this->addSql(<<<'SQL'
            DELETE organ FROM Organ organ
            INNER JOIN organ_duplicate duplicate ON duplicate.dupe_id = organ.id
            SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE organ_duplicate
            SQL);

        // Nothing points at a row in the other three, so a copy there can simply go.
        foreach (['OrganMember', 'BoardMember', 'Keyholder'] as $table) {
            $this->addSql(sprintf(
                'DELETE target FROM %1$s target'
                . ' INNER JOIN ('
                . ' SELECT %2$s, MIN(id) AS keep_id FROM %1$s'
                . ' WHERE r_meeting_type IS NOT NULL AND r_meeting_number IS NOT NULL'
                . ' AND r_decision_point IS NOT NULL AND r_decision_number IS NOT NULL AND r_sequence IS NOT NULL'
                . ' GROUP BY %2$s HAVING COUNT(*) > 1'
                . ' ) duplicate'
                . ' ON duplicate.r_meeting_type = target.r_meeting_type'
                . ' AND duplicate.r_meeting_number = target.r_meeting_number'
                . ' AND duplicate.r_decision_point = target.r_decision_point'
                . ' AND duplicate.r_decision_number = target.r_decision_number'
                . ' AND duplicate.r_sequence = target.r_sequence'
                . ' WHERE target.id <> duplicate.keep_id',
                $table,
                self::REFERENCE_COLUMNS,
            ));
        }

        // The foreign key comes off first: MariaDB will not let go of the only index covering it, not even when the
        // replacement is offered in the same breath.
        foreach (self::REFERENCE_TABLES as $table => [$unique, $index, $foreignKey]) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $foreignKey));
            $this->addSql(sprintf(
                'ALTER TABLE %1$s DROP INDEX %2$s, ADD UNIQUE INDEX %3$s (%4$s)',
                $table,
                $index,
                $unique,
                self::REFERENCE_COLUMNS,
            ));
            $this->addSql(sprintf(
                'ALTER TABLE %1$s ADD CONSTRAINT %2$s FOREIGN KEY (%3$s)'
                . ' REFERENCES SubDecision (meeting_type, meeting_number, decision_point, decision_number, sequence)',
                $table,
                $foreignKey,
                self::REFERENCE_COLUMNS,
            ));
        }

        // A regulation decision is about a body, and which body that is comes from the abbreviation column now. Only a
        // founding decision gives a body its name, so the name column is emptied here.
        $this->addSql(<<<'SQL'
            UPDATE SubDecision
            SET abbr = name,
                name = NULL
            WHERE type = 'organ_regulation'
              AND abbr IS NULL
              AND name IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE SubDecision
            SET name = abbr,
                abbr = NULL
            WHERE type = 'organ_regulation'
              AND name IS NULL
              AND abbr IS NOT NULL
            SQL);

        foreach (self::REFERENCE_TABLES as $table => [$unique, $index, $foreignKey]) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $foreignKey));
            $this->addSql(sprintf(
                'ALTER TABLE %1$s DROP INDEX %2$s, ADD INDEX %3$s (%4$s)',
                $table,
                $unique,
                $index,
                self::REFERENCE_COLUMNS,
            ));
            $this->addSql(sprintf(
                'ALTER TABLE %1$s ADD CONSTRAINT %2$s FOREIGN KEY (%3$s)'
                . ' REFERENCES SubDecision (meeting_type, meeting_number, decision_point, decision_number, sequence)',
                $table,
                $foreignKey,
                self::REFERENCE_COLUMNS,
            ));
        }

        // Widening the key again only makes room for copies to be written; the ones that were merged away stay gone.
        $this->addSql(<<<'SQL'
            ALTER TABLE MailingListMember
              DROP PRIMARY KEY,
              ADD PRIMARY KEY (mailingList, member, email),
              ADD UNIQUE INDEX mailinglistmember_unique_idx (mailingList, member, email)
            SQL);
    }
}
