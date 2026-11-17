<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20261116182620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Organ information revisions carry labels, like the activity and vacancy revisions do.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE OrganInformationRevisionLabelAssignment (organinformationrevision_id INT NOT NULL, organlabel_id INT NOT NULL, INDEX IDX_18BA434A43C0C70A (organinformationrevision_id), INDEX IDX_18BA434A6B761011 (organlabel_id), PRIMARY KEY (organinformationrevision_id, organlabel_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE OrganLabel (id INT AUTO_INCREMENT NOT NULL, retired TINYINT DEFAULT 0 NOT NULL, name_id INT NOT NULL, UNIQUE INDEX UNIQ_9E3BD72071179CD6 (name_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE OrganInformationRevisionLabelAssignment ADD CONSTRAINT FK_18BA434A43C0C70A FOREIGN KEY (organinformationrevision_id) REFERENCES OrganInformationRevision (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE OrganInformationRevisionLabelAssignment ADD CONSTRAINT FK_18BA434A6B761011 FOREIGN KEY (organlabel_id) REFERENCES OrganLabel (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE OrganLabel ADD CONSTRAINT FK_9E3BD72071179CD6 FOREIGN KEY (name_id) REFERENCES DecisionLocalisedText (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE OrganInformationRevisionLabelAssignment DROP FOREIGN KEY FK_18BA434A43C0C70A');
        $this->addSql('ALTER TABLE OrganInformationRevisionLabelAssignment DROP FOREIGN KEY FK_18BA434A6B761011');
        $this->addSql('ALTER TABLE OrganLabel DROP FOREIGN KEY FK_9E3BD72071179CD6');
        $this->addSql('DROP TABLE OrganInformationRevisionLabelAssignment');
        $this->addSql('DROP TABLE OrganLabel');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
