<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260922102347 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A member can choose which colours a revision review marks additions and removals with.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE UserSettings ADD colourVision VARCHAR(255) DEFAULT 'default' NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE UserSettings DROP colourVision');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
