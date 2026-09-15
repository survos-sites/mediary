<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\MediaRecordRepository;
use App\Workflow\MediaRecordFlow;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: MediaRecordRepository::class)]
#[ORM\Table]
#[ORM\Index(name: 'idx_media_record_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_media_record_record_key', columns: ['record_key'])]
#[ApiResource(
    operations: [
        new GetCollection(),
    ]
)]
final class MediaRecord implements MarkingInterface, \Stringable
{
    use MarkingTrait;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 16)]
    #[Groups(['media_record.read'])]
    public string $id;

    #[ORM\Column(name: 'record_key', type: Types::STRING, length: 191, unique: true)]
    #[Groups(['media_record.read'])]
    public string $recordKey;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['media_record.read'])]
    public ?string $label = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['media_record.read'])]
    public ?string $sourceUrl = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    #[Groups(['media_record.read'])]
    public ?string $sourceMime = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['media_record.read'])]
    public ?string $ocrText = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['media_record.read'])]
    public ?array $sourceMeta = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['media_record.read'])]
    public ?array $context = null;

    #[Groups(['media_record.read'])]
    public ?string $filename {
        get {
            $value = $this->sourceMeta['filename'] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }

            $firstAsset = $this->assets->first();
            $firstAssetUrl = $firstAsset instanceof Asset ? $firstAsset->originalUrl : null;

            return $this->filenameFromUrl($this->sourceUrl)
                ?? $this->filenameFromUrl($firstAssetUrl);
        }
    }

    #[Groups(['media_record.read'])]
    public ?string $extension {
        get {
            $value = $this->sourceMeta['extension'] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }

            $filename = $this->filename;
            if (!is_string($filename) || $filename === '') {
                return null;
            }

            $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

            return $ext !== '' ? $ext : null;
        }
    }

    #[Groups(['media_record.read'])]
    public int $pageCount {
        get => (int) ($this->sourceMeta['page_count'] ?? $this->childCount);
    }

    #[Groups(['media_record.read'])]
    public ?string $firstPageAssetId {
        get {
            $value = $this->sourceMeta['first_asset_id'] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }

            $firstAsset = $this->assets->first();
            return $firstAsset instanceof Asset ? $firstAsset->id : null;
        }
    }

    private function filenameFromUrl(?string $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if ($path === '') {
            return null;
        }

        $filename = basename($path);
        return $filename !== '' ? $filename : null;
    }

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['media_record.read'])]
    public int $childCount = 0;

    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $aiQueue = [];

    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $aiCompleted = [];

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    public bool $aiLocked = false;

    #[ORM\OneToMany(mappedBy: 'mediaRecord', targetEntity: Asset::class, cascade: ['persist'])]
    public Collection $assets;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['media_record.read'])]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $recordKey)
    {
        $normalized = trim($recordKey);
        if ($normalized === '') {
            throw new \InvalidArgumentException('media record key cannot be empty');
        }

        $this->recordKey = $normalized;
        $this->id = substr(hash('xxh3', strtolower($normalized)), 0, 16);
        $this->createdAt = new \DateTimeImmutable();
        $this->marking = MediaRecordFlow::PLACE_NEW;
        $this->assets = new ArrayCollection();
    }

    public function addAsset(Asset $asset): void
    {
        if (!$this->assets->contains($asset)) {
            $this->assets->add($asset);
        }
        if ($asset->mediaRecord !== $this) {
            $asset->mediaRecord = $this;
        }
        $this->childCount = $this->assets->count();
    }

    public function __toString(): string
    {
        return $this->recordKey;
    }
}
