<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260823170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the members\' authentication key. It was a long-lived secret generated for every member so that'
            . ' a linked system could verify an update, and nothing in the application ever read it back.'
            . ' DESTRUCTIVE — the keys go with the column, and a new one cannot be derived from anything kept.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE member DROP authenticationkey');
    }

    public function down(Schema $schema): void
    {
        // The column comes back empty: a key is random, so there is nothing to restore it from.
        $this->addSql('ALTER TABLE member ADD authenticationkey VARCHAR(255) DEFAULT NULL');
    }
}
