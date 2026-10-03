<?php

declare(strict_types=1);

namespace Database\Migrations;

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
final class Version20261003155500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert SIMPLE_ARRAY columns to JSON (ApiPrincipal.permissions, ProspectiveMember.lists)';
    }

    public function up(Schema $schema): void
    {
        $principals = $this->connection->fetchAllAssociative(
            'SELECT id, permissions FROM ApiPrincipal WHERE permissions IS NOT NULL',
        );
        foreach ($principals as $row) {
            $decoded = explode(',', $row['permissions']);
            $json = json_encode($decoded);
            $this->connection->executeStatement(
                'UPDATE ApiPrincipal SET permissions = :permissions WHERE id = :id',
                [
                    'permissions' => $json,
                    'id' => $row['id'],
                ],
            );
        }

        $this->addSql('ALTER TABLE ApiPrincipal ALTER permissions TYPE JSON USING permissions::json');

        $prospectiveMembers = $this->connection->fetchAllAssociative(
            'SELECT lidnr, lists FROM ProspectiveMember WHERE lists IS NOT NULL',
        );
        foreach ($prospectiveMembers as $row) {
            $decoded = explode(',', $row['lists']);
            $json = json_encode($decoded);
            $this->connection->executeStatement(
                'UPDATE ProspectiveMember SET lists = :lists WHERE lidnr = :lidnr',
                [
                    'lists' => $json,
                    'lidnr' => $row['lidnr'],
                ],
            );
        }

        $this->addSql('ALTER TABLE ProspectiveMember ALTER lists TYPE JSON USING lists::json');
    }

    public function down(Schema $schema): void
    {
        // Type change must run before the data conversion because upstream
        // migrations defer DDL via addSql; here the updates must run after it.
        $this->connection->executeStatement('ALTER TABLE ApiPrincipal ALTER permissions TYPE text');

        $principals = $this->connection->fetchAllAssociative(
            'SELECT id, permissions FROM ApiPrincipal WHERE permissions IS NOT NULL',
        );
        foreach ($principals as $row) {
            $decoded = json_decode($row['permissions'], true);
            $serialized = implode(',', $decoded);
            $this->connection->executeStatement(
                'UPDATE ApiPrincipal SET permissions = :permissions WHERE id = :id',
                [
                    'permissions' => $serialized,
                    'id' => $row['id'],
                ],
            );
        }

        $this->connection->executeStatement('ALTER TABLE ProspectiveMember ALTER lists TYPE text');

        $prospectiveMembers = $this->connection->fetchAllAssociative(
            'SELECT lidnr, lists FROM ProspectiveMember WHERE lists IS NOT NULL',
        );
        foreach ($prospectiveMembers as $row) {
            $decoded = json_decode($row['lists'], true);
            $serialized = implode(',', $decoded);
            $this->connection->executeStatement(
                'UPDATE ProspectiveMember SET lists = :lists WHERE lidnr = :lidnr',
                [
                    'lists' => $serialized,
                    'lidnr' => $row['lidnr'],
                ],
            );
        }
    }
}
