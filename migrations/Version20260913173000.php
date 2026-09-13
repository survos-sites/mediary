<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913173000 extends AbstractMigration
{
    public function getDescription(): string { return 'Preserve full collection titles when registering media records'; }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '3s'");
        $this->addSql('ALTER TABLE media_record ALTER COLUMN label TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Existing titles may exceed 255 characters; narrowing the column would risk data loss.');
    }
}
