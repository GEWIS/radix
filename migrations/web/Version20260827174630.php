<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260827174630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the devices an account has signed in from, so that signing in again from one does not raise a'
            . ' notice nobody has a reason to read. A session row cannot serve, being deleted on sign-out and left'
            . ' unusable by a closed private window.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE KnownDevice (userIdentifier VARCHAR(255) NOT NULL, firewallName VARCHAR(255) NOT NULL, fingerprint VARCHAR(255) NOT NULL, browser VARCHAR(255) DEFAULT NULL, operatingSystem VARCHAR(255) DEFAULT NULL, firstSeenAt DATETIME NOT NULL, lastSeenAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, INDEX IDX_1047E8DB72C2D33A (lastSeenAt), UNIQUE INDEX UNIQ_1047E8DB750FAC4349EB2E5FC0B754A (userIdentifier, firewallName, fingerprint), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE KnownDevice');
    }
}
