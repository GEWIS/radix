<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260830120856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Room for what a member changes about themselves: the address they asked to be reached at while that'
            . ' is waiting to be confirmed, the audit entries saying an address or an e-mail address changed, and'
            . ' whether a mailing list is one a member may put themselves on.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ActionLink ADD newEmail VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ActionLink ADD previousEmail VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ActionLink ADD requestedOn TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE AuditEntry ADD addressType VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE AuditEntry ADD detailAction VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE AuditEntry ADD oldEmail VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE AuditEntry ADD newEmail VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE MailingList ADD selfService BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ActionLink DROP newEmail');
        $this->addSql('ALTER TABLE ActionLink DROP previousEmail');
        $this->addSql('ALTER TABLE ActionLink DROP requestedOn');
        $this->addSql('ALTER TABLE AuditEntry DROP addressType');
        $this->addSql('ALTER TABLE AuditEntry DROP detailAction');
        $this->addSql('ALTER TABLE AuditEntry DROP oldEmail');
        $this->addSql('ALTER TABLE AuditEntry DROP newEmail');
        $this->addSql('ALTER TABLE MailingList DROP selfService');
    }
}
