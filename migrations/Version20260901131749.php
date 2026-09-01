<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260901131749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE asset DROP pending_steps');
        $this->addSql('ALTER TABLE file DROP pending_steps');
        $this->addSql('ALTER TABLE media ADD info JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE media_record DROP pending_steps');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA toolkit_experimental');
        $this->addSql('CREATE SCHEMA timescaledb_information');
        $this->addSql('CREATE SCHEMA timescaledb_experimental');
        $this->addSql('CREATE SCHEMA _timescaledb_internal');
        $this->addSql('CREATE SCHEMA _timescaledb_functions');
        $this->addSql('CREATE SCHEMA _timescaledb_config');
        $this->addSql('CREATE SCHEMA _timescaledb_catalog');
        $this->addSql('CREATE SCHEMA _timescaledb_cache');
        $this->addSql('ALTER TABLE asset ADD pending_steps JSON DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE file ADD pending_steps JSON DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE media DROP info');
        $this->addSql('ALTER TABLE media_record ADD pending_steps JSON DEFAULT \'{}\' NOT NULL');
    }
}
