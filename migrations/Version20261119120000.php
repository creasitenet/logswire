<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retention prunes by received_at / last_seen_at only; the existing composite
 * indexes start with project_id and cannot serve those full-table scans.
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add standalone retention indexes on events.received_at and issues.last_seen_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_events_received_at ON events (received_at)');
        $this->addSql('CREATE INDEX idx_issues_last_seen_at ON issues (last_seen_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_events_received_at');
        $this->addSql('DROP INDEX idx_issues_last_seen_at');
    }
}
