<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260830125316 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Room for what a member answered to the offer of staying on as a graduate. The dates it is about are'
            . ' the columns a renewal link and a change of email address already use, and mean the same thing.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ActionLink ADD outcome VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ActionLink DROP outcome');
    }
}
