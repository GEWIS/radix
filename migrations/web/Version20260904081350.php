<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260904081350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Carry the study a member follows over into the projection. It was recorded in the ledger only, so'
            . ' nothing on the website could tell a bachelor student from a master student. Existing rows are set to'
            . ' Unknown; they take their real value the next time the ledger writes the member, or at once when the'
            . ' projection is rebuilt with app:decision:generate.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Member ADD study VARCHAR(255) NOT NULL');
        $this->addSql("UPDATE Member SET study = 'Unknown'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Member DROP study');
    }
}
