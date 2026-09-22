<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260922120454 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A label that is in use can be retired instead of removed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ActivityLabel ADD retired TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE VacancyLabel ADD retired TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ActivityLabel DROP retired');
        $this->addSql('ALTER TABLE VacancyLabel DROP retired');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
