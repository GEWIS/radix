<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260826180346 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the decision a virtual decision repeats. A virtual meeting exists to put on the record what a'
            . ' real meeting decided, and naming that decision keeps the two from reading as two.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE decision ADD c_meeting_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE decision ADD c_meeting_number INT DEFAULT NULL');
        $this->addSql('ALTER TABLE decision ADD c_point INT DEFAULT NULL');
        $this->addSql('ALTER TABLE decision ADD c_number INT DEFAULT NULL');
        $this->addSql('ALTER TABLE decision ADD CONSTRAINT FK_7DDADC1E140758E56160025EAB6D371DC9895F98 FOREIGN KEY (c_meeting_type, c_meeting_number, c_point, c_number) REFERENCES Decision (meeting_type, meeting_number, point, number) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7DDADC1E140758E56160025EAB6D371DC9895F98 ON decision (c_meeting_type, c_meeting_number, c_point, c_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Decision DROP CONSTRAINT FK_7DDADC1E140758E56160025EAB6D371DC9895F98');
        $this->addSql('DROP INDEX IDX_7DDADC1E140758E56160025EAB6D371DC9895F98');
        $this->addSql('ALTER TABLE Decision DROP c_meeting_type');
        $this->addSql('ALTER TABLE Decision DROP c_meeting_number');
        $this->addSql('ALTER TABLE Decision DROP c_point');
        $this->addSql('ALTER TABLE Decision DROP c_number');
    }
}
