<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260822130514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make an API principal\'s token unique and indexed; it is the credential every API request is looked'
            . ' up by.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX apiprincipal_token_unique_idx ON apiprincipal (token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX apiprincipal_token_unique_idx');
    }
}
