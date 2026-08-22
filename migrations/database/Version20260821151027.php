<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260821151027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retire the register\'s own accounts. An audit entry names the member who made it rather than the login'
            . ' it was made under, which every account carried anyway: they are named after the member number they'
            . ' belong to. DESTRUCTIVE — the `users` table goes, and with it any local account that is not a member.';
    }

    /**
     * A login is `m` and four or five digits at the directory's domain, which is the member's own number. The entries
     * are re-pointed at that member before the accounts they named are dropped.
     *
     * Anything else in `users` is a local account belonging to nobody in the register -- the seeded `admin` is the one
     * example -- so an entry made under one is left naming nobody rather than being attributed to a member it was not.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auditentry ADD member_lidnr INT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE auditentry entry
            SET member_lidnr = CAST(SUBSTRING(account.login FROM '^[mM]([0-9]{4,5})@') AS INTEGER)
            FROM users account
            WHERE account.id = entry.user_id
              AND account.login ~* '^m[0-9]{4,5}@GEWISWG\.GEWIS\.NL$'
            SQL);

        // Only where the member is actually on the books. The mapping is exact, but a foreign key refuses the whole
        // statement over a single row that names somebody who is not, and an entry naming nobody is the lesser loss.
        $this->addSql(<<<'SQL'
            UPDATE auditentry
            SET member_lidnr = NULL
            WHERE member_lidnr IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM member WHERE member.lidnr = auditentry.member_lidnr)
            SQL);

        // Dropping the column takes the key that was on it with it.
        $this->addSql('ALTER TABLE auditentry DROP COLUMN user_id');
        $this->addSql('ALTER TABLE auditentry ADD CONSTRAINT FK_DE382FBBB44475EE FOREIGN KEY (member_lidnr) REFERENCES Member (lidnr) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_DE382FBBB44475EE ON auditentry (member_lidnr)');

        $this->addSql('DROP TABLE users');
    }

    /**
     * The accounts themselves cannot come back: what they were is not recorded anywhere else, and a password least of
     * all. The table returns empty and the entries return naming nobody, which is as much as stepping back can honestly
     * restore.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id SERIAL NOT NULL,
                login VARCHAR(255) NOT NULL,
                password VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9AA08CB10 ON users (login)');

        $this->addSql('ALTER TABLE auditentry DROP CONSTRAINT FK_DE382FBBB44475EE');
        $this->addSql('DROP INDEX IDX_DE382FBBB44475EE');
        $this->addSql('ALTER TABLE auditentry DROP COLUMN member_lidnr');
        $this->addSql('ALTER TABLE auditentry ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE auditentry ADD CONSTRAINT fk_2a5e8b2ba76ed395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }
}
