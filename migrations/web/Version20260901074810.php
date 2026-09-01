<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260901074810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split what device recognition rests on into three facts learned independently: the device, the'
            . ' network it arrived from, and the cookie it carried. One key over all of it announced every pairing of'
            . ' a laptop with a network as a new device, which for members moving between home and campus was most'
            . ' sign-ins. Existing device rows are cleared because their fingerprints were computed with the network'
            . ' inside and can never match again.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE KnownDeviceToken (userIdentifier VARCHAR(255) NOT NULL, firewallName VARCHAR(255) NOT NULL, firstSeenAt DATETIME NOT NULL, lastSeenAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, tokenHash VARCHAR(255) NOT NULL, browser VARCHAR(255) DEFAULT NULL, operatingSystem VARCHAR(255) DEFAULT NULL, INDEX IDX_4C0F20B472C2D33A (lastSeenAt), UNIQUE INDEX UNIQ_4C0F20B4750FAC4349EB2E5E5C96920 (userIdentifier, firewallName, tokenHash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE KnownNetwork (userIdentifier VARCHAR(255) NOT NULL, firewallName VARCHAR(255) NOT NULL, firstSeenAt DATETIME NOT NULL, lastSeenAt DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, fingerprint VARCHAR(255) NOT NULL, INDEX IDX_7B9C4A9972C2D33A (lastSeenAt), UNIQUE INDEX UNIQ_7B9C4A99750FAC4349EB2E5FC0B754A (userIdentifier, firewallName, fingerprint), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('DELETE FROM KnownDevice');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE KnownDeviceToken');
        $this->addSql('DROP TABLE KnownNetwork');
        $this->addSql('DELETE FROM KnownDevice');
    }
}
