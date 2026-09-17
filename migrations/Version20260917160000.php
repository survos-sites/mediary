<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'asset.storage_bucket: a private source bucket whose object is used in place (SourceBuckets)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset ADD storage_bucket TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset DROP storage_bucket');
    }
}
