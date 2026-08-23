<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260823170100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the members\' authentication key from the projection, the ledger having dropped its own.'
            . ' DESTRUCTIVE — the keys go with the column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Member DROP authenticationKey');
    }

    public function down(Schema $schema): void
    {
        // The column comes back empty: the projection is rebuilt from the ledger, which no longer holds a key either.
        $this->addSql('ALTER TABLE Member ADD authenticationKey VARCHAR(255) DEFAULT NULL');
    }
}
