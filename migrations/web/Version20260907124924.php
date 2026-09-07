<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260907124924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the flat documents and minutes GEWISWEB kept, which Version20260731182323 renamed out of the way'
            . ' as LegacyMeetingDocument and LegacyMeetingMinutes so that the one-shot migrator could read them. That'
            . ' migrator is gone and the meetings live in the agenda-point and version model, so the two tables are'
            . ' nothing but a copy of what has already been carried over. Run it only once every meeting has been:'
            . ' down() rebuilds the tables, but nothing brings the rows back.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE LegacyMeetingDocument DROP FOREIGN KEY `FK_1FBA0797602FAFFB96F82E16`');
        $this->addSql('ALTER TABLE LegacyMeetingMinutes DROP FOREIGN KEY `FK_A44E1FD8602FAFFB96F82E16`');
        $this->addSql('DROP TABLE LegacyMeetingDocument');
        $this->addSql('DROP TABLE LegacyMeetingMinutes');
    }

    public function down(Schema $schema): void
    {
        // Empty, as the rows they held are not recoverable from this side. The files they pointed at are: the
        // migrator copied them out of the legacy pool rather than moving them.
        $this->addSql('CREATE TABLE LegacyMeetingDocument (id INT AUTO_INCREMENT NOT NULL, meeting_type ENUM(\'BV\', \'ALV\', \'VV\', \'Virt\') CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT DEFAULT NULL, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, path VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, displayPosition INT DEFAULT 0 NOT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, INDEX IDX_1FBA0797602FAFFB96F82E16 (meeting_type, meeting_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE LegacyMeetingMinutes (meeting_type ENUM(\'BV\', \'ALV\', \'VV\', \'Virt\') CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, meeting_number INT NOT NULL, path VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_uca1400_ai_ci`, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, PRIMARY KEY (meeting_type, meeting_number)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE LegacyMeetingDocument ADD CONSTRAINT `FK_1FBA0797602FAFFB96F82E16` FOREIGN KEY (meeting_type, meeting_number) REFERENCES Meeting (type, number)');
        $this->addSql('ALTER TABLE LegacyMeetingMinutes ADD CONSTRAINT `FK_A44E1FD8602FAFFB96F82E16` FOREIGN KEY (meeting_type, meeting_number) REFERENCES Meeting (type, number)');
    }
}
