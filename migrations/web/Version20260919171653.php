<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260919171653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The share cards of an activity, one per language, drawn when a revision is approved, and when they'
            . ' next go stale.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Activity ADD shareImagePaths JSON DEFAULT NULL, ADD shareImageStaleAt DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Activity DROP shareImagePaths, DROP shareImageStaleAt');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
