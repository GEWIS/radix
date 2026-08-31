<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260830114700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the table of changes a member proposed for the secretary to approve. Nothing has written to it'
            . ' since the website and the register became one application, and members change their own details'
            . ' themselves now.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MemberUpdate DROP CONSTRAINT fk_6fa192d9d665e01d');
        $this->addSql('DROP TABLE MemberUpdate');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE MemberUpdate (lidnr INT NOT NULL, requesteddate DATE NOT NULL, email VARCHAR(255) NOT NULL, lastname VARCHAR(255) NOT NULL, middlename VARCHAR(255) NOT NULL, initials VARCHAR(255) NOT NULL, firstname VARCHAR(255) NOT NULL, PRIMARY KEY (lidnr))');
        $this->addSql('ALTER TABLE MemberUpdate ADD CONSTRAINT fk_6fa192d9d665e01d FOREIGN KEY (lidnr) REFERENCES member (lidnr) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
