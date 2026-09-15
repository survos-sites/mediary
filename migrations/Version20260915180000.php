<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop meili-bundle\'s index_info registry; search moved to Elasticsearch';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS index_info');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE index_info (index_name VARCHAR(255) NOT NULL, last_indexed TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, document_count INT NOT NULL, settings JSONB NOT NULL, task_id VARCHAR(255) DEFAULT NULL, primary_key VARCHAR(255) NOT NULL, batch_id VARCHAR(255) DEFAULT NULL, status VARCHAR(20) DEFAULT NULL, label VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, aggregator VARCHAR(255) DEFAULT NULL, institution VARCHAR(255) DEFAULT NULL, country VARCHAR(255) DEFAULT NULL, locale VARCHAR(255) DEFAULT NULL, PRIMARY KEY (index_name))');
    }
}
