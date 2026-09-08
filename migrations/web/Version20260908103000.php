<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260908103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SecurityLog, the queryable half of the security trail: one row per thing that happened to an'
            . ' account, written beside the `security` log channel by App\\Service\\User\\SecurityEventLogger. The'
            . ' file keeps the copy that survives a rolled-back request; this table is what the administration'
            . ' searches and what a member\'s data export hands over. Rows are pruned on a retention period by'
            . ' app:user:prune-security-log, so nothing here grows without end.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE SecurityLog (id INT AUTO_INCREMENT NOT NULL, occurredAt DATETIME NOT NULL, event VARCHAR(255) NOT NULL, userIdentifier VARCHAR(255) DEFAULT NULL, firewallName VARCHAR(255) DEFAULT NULL, actorIdentifier VARCHAR(255) DEFAULT NULL, ipAddress VARCHAR(255) DEFAULT NULL, browser VARCHAR(255) DEFAULT NULL, operatingSystem VARCHAR(255) DEFAULT NULL, requestId VARCHAR(255) DEFAULT NULL, detail JSON NOT NULL, INDEX security_log_user_idx (userIdentifier, occurredAt), INDEX security_log_occurred_idx (occurredAt), INDEX security_log_event_idx (event), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // The rows are not recoverable from anywhere else the database knows about. The log files record the same
        // events for as long as they are kept, which is what makes dropping this table recoverable at all.
        $this->addSql('DROP TABLE SecurityLog');
    }
}
