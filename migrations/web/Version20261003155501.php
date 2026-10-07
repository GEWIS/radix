<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

use function explode;
use function implode;
use function json_decode;
use function json_encode;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20261003155501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert SIMPLE_ARRAY column to JSON (externalapp.claims)';
    }

    public function up(Schema $schema): void
    {
        $externalApps = $this->connection->fetchAllAssociative(
            'SELECT id, claims FROM ExternalApp WHERE claims IS NOT NULL',
        );
        foreach ($externalApps as $row) {
            $decoded = explode(',', $row['claims']);
            $json = json_encode($decoded);
            $this->connection->executeStatement(
                'UPDATE ExternalApp SET claims = :claims WHERE id = :id',
                [
                    'claims' => $json,
                    'id' => $row['id'],
                ],
            );
        }

        $this->addSql('ALTER TABLE ExternalApp MODIFY claims JSON');
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('ALTER TABLE ExternalApp MODIFY claims TEXT');

        $externalApps = $this->connection->fetchAllAssociative(
            'SELECT id, claims FROM ExternalApp WHERE claims IS NOT NULL',
        );
        foreach ($externalApps as $row) {
            $decoded = json_decode($row['claims'], true);
            $serialized = implode(',', $decoded);
            $this->connection->executeStatement(
                'UPDATE ExternalApp SET claims = :claims WHERE id = :id',
                [
                    'claims' => $serialized,
                    'id' => $row['id'],
                ],
            );
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
