<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260831101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the remember-me token a rotation replaced, and the moment it stops being accepted. Two tabs woken'
            . ' together present the same token and only one of them can rotate it, which without this reads as a'
            . ' replayed cookie and signs the account out everywhere.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Session ADD previousHashedToken VARCHAR(255) DEFAULT NULL, ADD previousTokenValidUntil DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Session DROP previousHashedToken, DROP previousTokenValidUntil');
    }
}
