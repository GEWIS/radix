<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260904093855 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give a sign-up list the priority modifiers the board asked for: an order to serve its membership'
            . ' tiers, study phases and cohorts in, seats held for those tiers or for the organising body, and the'
            . ' roles an activity cannot go ahead without together with the role each subscriber was handed. Every'
            . ' one of them is off until an organiser turns it on, so existing lists allocate their places exactly'
            . ' as they did.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE SignupRole (name VARCHAR(255) NOT NULL, minimum INT NOT NULL, position INT DEFAULT 0 NOT NULL, id INT AUTO_INCREMENT NOT NULL, signuplist_id INT NOT NULL, INDEX IDX_34EC7A2DBDD669D6 (signuplist_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE SignupRole ADD CONSTRAINT FK_34EC7A2DBDD669D6 FOREIGN KEY (signuplist_id) REFERENCES SignupList (id)');
        $this->addSql('ALTER TABLE Signup ADD role_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE Signup ADD CONSTRAINT FK_490F1BD9D60322AC FOREIGN KEY (role_id) REFERENCES SignupRole (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_490F1BD9D60322AC ON Signup (role_id)');
        $this->addSql('ALTER TABLE SignupList ADD membershipTierOrder JSON DEFAULT NULL, ADD membershipPriorityMode VARCHAR(255) DEFAULT NULL, ADD membershipSeats JSON DEFAULT NULL, ADD cohortTierOrder JSON DEFAULT NULL, ADD programTypeOrder JSON DEFAULT NULL, ADD organisingCommitteeSeats INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE SignupRole DROP FOREIGN KEY FK_34EC7A2DBDD669D6');
        $this->addSql('DROP TABLE SignupRole');
        $this->addSql('ALTER TABLE Signup DROP FOREIGN KEY FK_490F1BD9D60322AC');
        $this->addSql('DROP INDEX IDX_490F1BD9D60322AC ON Signup');
        $this->addSql('ALTER TABLE Signup DROP role_id');
        $this->addSql('ALTER TABLE SignupList DROP membershipTierOrder, DROP membershipPriorityMode, DROP membershipSeats, DROP cohortTierOrder, DROP programTypeOrder, DROP organisingCommitteeSeats');
    }
}
