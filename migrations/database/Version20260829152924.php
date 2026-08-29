<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260829152924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give a free-text decision an English text of its own. The column it already had holds the Dutch text'
            . ' that was decided, and is named after the language it is in like everywhere else.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE SubDecision RENAME COLUMN content TO contentNL');
        $this->addSql('ALTER TABLE SubDecision ADD contentEN TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE SubDecision DROP contentEN');
        $this->addSql('ALTER TABLE SubDecision RENAME COLUMN contentNL TO content');
    }
}
