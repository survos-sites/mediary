<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001160704 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the caller of command-bundle processes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE command_process ADD caller VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE command_process DROP caller');
    }
}
