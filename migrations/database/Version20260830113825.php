<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260830113825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep only half of an action link\'s token, and the hash of the other half, so a link that was sent'
            . ' cannot be read back out of the register. Every link that is outstanding is given a token nobody'
            . ' holds, which is to say every renewal and payment link already in a mailbox stops working.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ActionLink ADD selector VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ActionLink ADD hashedToken VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ActionLink ADD tempHash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ActionLink ADD tempHashExpiresAt TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Random on both halves rather than derived from the token that is being dropped: a link whose selector could
        // be worked out from what was mailed would still be half of a credential.
        $this->addSql('UPDATE ActionLink SET selector = md5(random()::text || id::text), hashedToken = encode(sha256((random()::text || id::text)::bytea), \'hex\')');
        $this->addSql('ALTER TABLE ActionLink ALTER COLUMN selector SET NOT NULL');
        $this->addSql('ALTER TABLE ActionLink ALTER COLUMN hashedToken SET NOT NULL');
        $this->addSql('ALTER TABLE ActionLink DROP token');
        $this->addSql('CREATE INDEX IDX_action_link_selector ON ActionLink (selector)');
        $this->addSql('CREATE INDEX IDX_action_link_temp_hash ON ActionLink (tempHash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_action_link_selector');
        $this->addSql('DROP INDEX IDX_action_link_temp_hash');
        $this->addSql('ALTER TABLE ActionLink ADD token VARCHAR(255) DEFAULT NULL');
        // Going back leaves the links no more usable than coming forward did, for the same reason.
        $this->addSql('UPDATE ActionLink SET token = md5(random()::text || id::text)');
        $this->addSql('ALTER TABLE ActionLink ALTER COLUMN token SET NOT NULL');
        $this->addSql('ALTER TABLE ActionLink DROP selector');
        $this->addSql('ALTER TABLE ActionLink DROP hashedToken');
        $this->addSql('ALTER TABLE ActionLink DROP tempHash');
        $this->addSql('ALTER TABLE ActionLink DROP tempHashExpiresAt');
    }
}
