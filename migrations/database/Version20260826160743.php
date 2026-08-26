<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260826160743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the first day of a member\'s suspension. Its last day is the `until` the key codes already'
            . ' carry: both are a date on the shared sub-decision table, and the meaning is the same.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subdecision ADD since DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subdecision DROP since');
    }
}
