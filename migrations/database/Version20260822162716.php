<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260822162716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep only a hash of an API token, and give a principal a lifecycle. Anything that could read one row'
            . ' of this table could read a live credential for every consumer; a token is shown once and never'
            . ' again, so there is nothing to lose by not storing it. Also adds when a token was last used, when it'
            . ' expires, and when it was revoked.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE apiprincipal ADD tokenHash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE apiprincipal ADD tokenHint VARCHAR(5) DEFAULT NULL');
        $this->addSql('UPDATE apiprincipal SET tokenhint = RIGHT(token, 5), tokenhash = ENCODE(SHA256(CONVERT_TO(token, \'UTF8\')), \'hex\')');
        $this->addSql('ALTER TABLE apiprincipal ALTER tokenhash SET NOT NULL');
        $this->addSql('ALTER TABLE apiprincipal ALTER tokenhint SET NOT NULL');
        $this->addSql('DROP INDEX apiprincipal_token_unique_idx');
        $this->addSql('ALTER TABLE apiprincipal DROP token');
        $this->addSql('ALTER TABLE apiprincipal ADD lastUsedAt DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE apiprincipal ADD expiresAt DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE apiprincipal ADD revokedAt TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN apiprincipal.permissions IS \'\'');
        $this->addSql('CREATE UNIQUE INDEX apiprincipal_token_hash_unique_idx ON apiprincipal (tokenHash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE apiprincipal ADD token VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE apiprincipal SET token = tokenhash');
        $this->addSql('ALTER TABLE apiprincipal ALTER token SET NOT NULL');
        $this->addSql('DROP INDEX apiprincipal_token_hash_unique_idx');
        $this->addSql('ALTER TABLE apiprincipal DROP tokenHash');
        $this->addSql('ALTER TABLE apiprincipal DROP tokenHint');
        $this->addSql('ALTER TABLE apiprincipal DROP lastUsedAt');
        $this->addSql('ALTER TABLE apiprincipal DROP expiresAt');
        $this->addSql('ALTER TABLE apiprincipal DROP revokedAt');
        $this->addSql('COMMENT ON COLUMN apiprincipal.permissions IS \'(DC2Type:simple_array)\'');
        $this->addSql('CREATE UNIQUE INDEX apiprincipal_token_unique_idx ON apiprincipal (token)');
    }
}
