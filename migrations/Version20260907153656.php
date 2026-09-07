<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260907153656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the media table: mediary is the Asset side and never read it';
    }

    public function up(Schema $schema): void
    {
        // mediary owns Asset. `media` is the CLIENT-side reflection of an Asset, and having both
        // in one schema was confusing for humans and for anything reading this database.
        //
        // It was never mediary's data: the table existed only because media-bundle
        // auto-registers src/Entity for anything that requires it, and mediary required the
        // bundle for MediaIdentity/MediaKeyService/MediaSyncKeys/BatchPayloadDto/MediaUrlGenerator
        // presets and SidecarService. Those have since moved: the vocabulary to data-contracts,
        // the sidecar store here behind JSON-RPC. mediary no longer imports a single class from
        // media-bundle, so the requirement is gone and the mapping with it.
        //
        // 0 rows here and on production -- mediary never wrote to it.
        $this->addSql('DROP TABLE media');
    }

    public function down(Schema $schema): void
    {
        // Recreating the table would not restore the mapping, which now lives nowhere in this
        // app. A rollback that wants media back wants media-bundle back first.
        $this->addSql('CREATE TABLE media (id VARCHAR(32) NOT NULL, status VARCHAR(255) NOT NULL, provider VARCHAR(100) DEFAULT NULL, external_id VARCHAR(255) DEFAULT NULL, external_url TEXT DEFAULT NULL, raw_data JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, s3_url TEXT DEFAULT NULL, location JSON DEFAULT NULL, tags JSON NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, duration INT DEFAULT NULL, file_size BIGINT DEFAULT NULL, mime_type VARCHAR(100) DEFAULT NULL, title TEXT DEFAULT NULL, description TEXT DEFAULT NULL, type VARCHAR(255) NOT NULL, camera VARCHAR(255) DEFAULT NULL, exif_data JSON DEFAULT NULL, taken_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, view_count INT DEFAULT NULL, like_count INT DEFAULT NULL, chapters JSON DEFAULT NULL, subtitles JSON DEFAULT NULL, artist VARCHAR(255) DEFAULT NULL, album VARCHAR(255) DEFAULT NULL, bitrate INT DEFAULT NULL, small_url TEXT DEFAULT NULL, storage_key TEXT DEFAULT NULL, dataset VARCHAR(255) DEFAULT NULL, ai_queue JSON DEFAULT \'[]\' NOT NULL, ai_completed JSON DEFAULT \'[]\' NOT NULL, ai_locked BOOLEAN DEFAULT false NOT NULL, ai_document_type VARCHAR(255) DEFAULT NULL, info JSON DEFAULT NULL, marking VARCHAR(32) DEFAULT NULL, PRIMARY KEY (id))');
    }
}
