<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enable the unaccent extension for diacritics-insensitive search.
 */
final class Version20261009191800 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(Schema $schema): void
    {
        // Do not drop the extension; other tables may depend on it.
    }
}
