<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260826163509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the association year a board candidacy is for, held as the first of its two calendar years. The'
            . ' candidates that follow it are names and nothing else, so none of them carries a year of its own.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE SubDecision ADD boardYear INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE SubDecision DROP boardYear');
    }
}
