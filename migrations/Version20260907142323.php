<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260907142323 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media.marking (media-bundle 2.27.x); mediary maps the media table but never reads it';
    }

    public function up(Schema $schema): void
    {
        // media-bundle now carries a workflow marking on BaseMedia. mediary does not READ the
        // media table -- it maps it only because the bundle auto-registers src/Entity -- but the
        // mapping must still match the schema. 0 rows here and on production.
        //
        // The intended end state is dropping these tables entirely once mediary no longer
        // requires media-bundle. One dependency remains: SidecarService.
        $this->addSql('ALTER TABLE media ADD marking VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP marking');
    }
}
