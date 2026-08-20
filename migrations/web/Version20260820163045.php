<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use LogicException;

use function array_column;
use function array_diff_key;
use function array_filter;
use function in_array;
use function sprintf;

/**
 * The constraint tables below are one row per foreign key, which is what makes them readable at all.
 *
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 * phpcs:disable Squiz.Arrays.ArrayDeclaration.SingleLineNotAllowed
 */
final class Version20260820163045 extends AbstractMigration
{
    /**
     * `CASCADE` is for a row that means nothing without the person; `SET NULL` for something somebody else relies on,
     * which keeps standing while the name comes off it.
     */
    private const array CONSTRAINTS = [
        'FK_C2F3561DD665E01D' => ['table' => 'Address', 'column' => 'lidnr', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_3A8467A970E4FA78' => ['table' => 'MailingListMember', 'column' => 'member', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_C913C01A34A1C897' => ['table' => 'Authorization', 'column' => 'authorizer', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_C913C01A6804FB49' => ['table' => 'Authorization', 'column' => 'recipient', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_3BC4F1637597D3FE' => ['table' => 'Tag', 'column' => 'member_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_26AA8F6B7597D3FE' => ['table' => 'ProfilePhoto', 'column' => 'member_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_FA222A5AEBB4B8AD' => ['table' => 'Vote', 'column' => 'voter_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_B568B088A76ED395' => ['table' => 'PollVote', 'column' => 'user_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_490F1BD934A71B45' => ['table' => 'Signup', 'column' => 'user_lidnr', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_582037685F619F6E' => ['table' => 'SignupFieldValue', 'column' => 'signup_id', 'parent' => 'Signup', 'key' => 'id', 'action' => 'CASCADE'],
        'FK_ED52ACECD665E01D' => ['table' => 'PasswordReset', 'column' => 'lidnr', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],

        'FK_2DA17977D665E01D' => ['table' => 'User', 'column' => 'lidnr', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_A8503F73111993FE' => ['table' => 'UserRole', 'column' => 'lidnr_id', 'parent' => 'User', 'key' => 'lidnr', 'action' => 'CASCADE'],
        'FK_D9FD7EB6A76ED395' => ['table' => 'ExternalAppAuthentication', 'column' => 'user_id', 'parent' => 'User', 'key' => 'lidnr', 'action' => 'CASCADE'],

        'FK_55026B0C61220EA6' => ['table' => 'Activity', 'column' => 'creator_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_55026B0C170541A2' => ['table' => 'Activity', 'column' => 'cancelledBy_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_55026B0C35E74FC1' => ['table' => 'Activity', 'column' => 'unpublishedBy_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_274D085F4FA7FF98' => ['table' => 'SignupList', 'column' => 'drawnBy_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_248E557B61220EA6' => ['table' => 'Poll', 'column' => 'creator_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_C86340FF34A71B45' => ['table' => 'PollComment', 'column' => 'user_lidnr', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_5EF688A71E253D71' => ['table' => 'EditLock', 'column' => 'lockedBy_id', 'parent' => 'User', 'key' => 'lidnr', 'action' => 'SET NULL'],

        'FK_F7309B7AF675F31B' => ['table' => 'ActivityRevision', 'column' => 'author_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_F7309B7A70574616' => ['table' => 'ActivityRevision', 'column' => 'reviewer_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_F7309B7AA19E445F' => ['table' => 'ActivityRevision', 'column' => 'lastEditedBy_id', 'parent' => 'User', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_DEE0948DF675F31B' => ['table' => 'ActivityRevisionComment', 'column' => 'author_id', 'parent' => 'User', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_285C37816995AC4C' => ['table' => 'ActivityRevisionEdit', 'column' => 'editor_id', 'parent' => 'User', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_48CAB2AEF675F31B' => ['table' => 'CompanyRevision', 'column' => 'author_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_48CAB2AE70574616' => ['table' => 'CompanyRevision', 'column' => 'reviewer_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_48CAB2AEA19E445F' => ['table' => 'CompanyRevision', 'column' => 'lastEditedBy_id', 'parent' => 'User', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_E65AF115F675F31B' => ['table' => 'CompanyRevisionComment', 'column' => 'author_id', 'parent' => 'User', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_FFE914BFF675F31B' => ['table' => 'VacancyRevision', 'column' => 'author_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_FFE914BF70574616' => ['table' => 'VacancyRevision', 'column' => 'reviewer_id', 'parent' => 'Member', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_FFE914BFA19E445F' => ['table' => 'VacancyRevision', 'column' => 'lastEditedBy_id', 'parent' => 'User', 'key' => 'lidnr', 'action' => 'SET NULL'],
        'FK_EE72B76BF675F31B' => ['table' => 'VacancyRevisionComment', 'column' => 'author_id', 'parent' => 'User', 'key' => 'lidnr', 'action' => 'SET NULL'],
    ];

    /**
     * The four SET NULL columns that were declared NOT NULL and have to be widened before they can hold one.
     */
    private const array WIDENED_COLUMNS = [
        ['table' => 'Activity', 'column' => 'creator_id', 'was' => 'INT NOT NULL', 'becomes' => 'INT DEFAULT NULL'],
        ['table' => 'Poll', 'column' => 'creator_id', 'was' => 'INT NOT NULL', 'becomes' => 'INT DEFAULT NULL'],
        ['table' => 'PollComment', 'column' => 'user_lidnr', 'was' => 'INT NOT NULL', 'becomes' => 'INT DEFAULT NULL'],
        ['table' => 'ActivityRevisionEdit', 'column' => 'editor_id', 'was' => 'INT NOT NULL', 'becomes' => 'INT DEFAULT NULL'],
    ];

    public function getDescription(): string
    {
        return 'Give every foreign key that names a member or an account an ON DELETE action, so a member can be'
            . ' removed at all: rows that are nobody\'s without the person CASCADE, rows somebody else relies on'
            . ' SET NULL. DESTRUCTIVE AND IRREVERSIBLE — it also sweeps away the rows that already name members who'
            . ' are gone, which the old members\'-administration sync left behind by running with the checks off.'
            . ' How much that comes to cannot be read off from here. Take a copy of the database first.';
    }

    public function up(Schema $schema): void
    {
        // Rows the code has always held to be impossible and the database never stopped: a member-kind photo tag or
        // sign-up naming nobody. Opening the attendance of a past activity fell over on exactly these. The answers go
        // first, because nothing takes them along yet.
        $this->addSql(<<<'SQL'
            DELETE FROM Tag WHERE dtype = 'member' AND member_id IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            DELETE value FROM SignupFieldValue value
            INNER JOIN Signup signup ON signup.id = value.signup_id
            WHERE signup.type = 'user' AND signup.user_lidnr IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            DELETE FROM Signup WHERE type = 'user' AND user_lidnr IS NULL
            SQL);

        // A foreign key cannot be given an ON DELETE action after the fact, so each is dropped and recreated. All of
        // them come off first because the widening and the sweep in between would not be allowed while one is on.
        foreach (self::CONSTRAINTS as $name => $constraint) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $constraint['table'], $name));
        }

        foreach (self::WIDENED_COLUMNS as $column) {
            $this->addSql(sprintf(
                'ALTER TABLE %s MODIFY %s %s',
                $column['table'],
                $column['column'],
                $column['becomes'],
            ));
        }

        // InnoDB validates a new key against the existing rows and refuses the whole key over a single orphan. There
        // are known to be orphans: the old members'-administration sync ran with the checks switched off and removed
        // members out from under everything naming them. Each is settled here the way its key would have settled it —
        // switching the checks off to get past this is what made the mess in the first place.
        foreach (self::sweepOrder() as $constraint) {
            if ('CASCADE' === $constraint['action']) {
                // `IS NOT NULL` throughout: an empty column names nobody rather than somebody who is gone.
                $this->addSql(sprintf(
                    'DELETE orphan FROM %1$s orphan WHERE orphan.%2$s IS NOT NULL'
                    . ' AND NOT EXISTS (SELECT 1 FROM %3$s named WHERE named.%4$s = orphan.%2$s)',
                    $constraint['table'],
                    $constraint['column'],
                    $constraint['parent'],
                    $constraint['key'],
                ));

                continue;
            }

            // WIDENED_COLUMNS had to run first for these four to be able to hold NULL at all.
            $this->addSql(sprintf(
                'UPDATE %1$s orphan SET orphan.%2$s = NULL WHERE orphan.%2$s IS NOT NULL'
                . ' AND NOT EXISTS (SELECT 1 FROM %3$s named WHERE named.%4$s = orphan.%2$s)',
                $constraint['table'],
                $constraint['column'],
                $constraint['parent'],
                $constraint['key'],
            ));
        }

        foreach (self::CONSTRAINTS as $name => $constraint) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
                $constraint['table'],
                $name,
                $constraint['column'],
                $constraint['parent'],
                $constraint['key'],
                $constraint['action'],
            ));
        }
    }

    /**
     * CONSTRAINTS in an order the sweep can run in: a row has to be swept before whatever it hangs off is swept out
     * from under it, or it is never seen as an orphan at all. CONSTRAINTS happens to be written in such an order, but
     * it is grouped for reading, so a regrouping would silently strand rows. Deriving the order keeps the two apart.
     *
     * @return array<string, array{table: string, column: string, parent: string, key: string, action: string}>
     */
    private static function sweepOrder(): array
    {
        $remaining = self::CONSTRAINTS;
        $ordered = [];

        while ([] !== $remaining) {
            $awaited = array_column($remaining, 'table');
            $ready = array_filter(
                $remaining,
                static fn (array $constraint): bool => !in_array($constraint['parent'], $awaited, true),
            );

            // Unreachable for the list as it stands; it is here so a later edit that introduces a cycle fails
            // visibly rather than spinning forever.
            if ([] === $ready) {
                throw new LogicException('The links to a member cannot be swept in any order: they wait on each other.');
            }

            $ordered += $ready;
            $remaining = array_diff_key($remaining, $ready);
        }

        return $ordered;
    }

    /**
     * Narrowing the columns again only holds up while every row still names somebody. A server not in strict mode does
     * not refuse a NULL, it silently writes 0 and hands the activity, poll, comment or edit to member number 0; and
     * nothing here sets `sql_mode`, so that cannot be assumed either way. Counting first turns it into a refusal to
     * start on every server alike.
     */
    public function preDown(Schema $schema): void
    {
        foreach (self::WIDENED_COLUMNS as $column) {
            $emptied = (int) $this->connection->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE %s IS NULL',
                $column['table'],
                $column['column'],
            ));

            $this->abortIf(
                0 !== $emptied,
                sprintf(
                    '%s.%s no longer names anybody on %d row(s), because the member or account it named has been'
                    . ' removed. Going back needs a member number on every one of them: put one back, or remove those'
                    . ' rows, before stepping back over this.',
                    $column['table'],
                    $column['column'],
                    $emptied,
                ),
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::CONSTRAINTS as $name => $constraint) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $constraint['table'], $name));
        }

        // The columns have to hold somebody again. That is free only where every row still names somebody, which is no
        // longer the same thing as nobody having been removed yet: the sweep on the way in empties these on rows that
        // were naming somebody long gone. Either way there is no name to put back, and rather than leave that to the
        // server the count above has already stopped this.
        foreach (self::WIDENED_COLUMNS as $column) {
            $this->addSql(sprintf(
                'ALTER TABLE %s MODIFY %s %s',
                $column['table'],
                $column['column'],
                $column['was'],
            ));
        }

        foreach (self::CONSTRAINTS as $name => $constraint) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
                $constraint['table'],
                $name,
                $constraint['column'],
                $constraint['parent'],
                $constraint['key'],
            ));
        }
    }
}
