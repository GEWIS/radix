<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260822175516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Close the gap between the ledger\'s schema and its mapping. None of this belongs to a feature: it is'
            . ' what five earlier migrations left behind, and until it is applied `make migration-diff` emits it'
            . ' again on every branch that touches an entity, so the migration check fails for reasons that have'
            . ' nothing to do with the branch.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE users_id_seq CASCADE');
        $this->addSql('ALTER TABLE configitem ALTER version SET DEFAULT 1');
        $this->addSql('COMMENT ON COLUMN membership.startDate IS \'\'');
        $this->addSql('COMMENT ON COLUMN prospectivemember.lists IS \'\'');
        $this->addSql('COMMENT ON COLUMN apiprincipal.permissions IS \'\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE users_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('ALTER TABLE ConfigItem ALTER version SET DEFAULT 1000');
        $this->addSql('COMMENT ON COLUMN Membership.startdate IS \'(DC2Type:stringable_datetime)\'');
        $this->addSql('COMMENT ON COLUMN ProspectiveMember.lists IS \'(DC2Type:simple_array)\'');
        $this->addSql('COMMENT ON COLUMN ApiPrincipal.permissions IS \'(DC2Type:simple_array)\'');
    }
}
