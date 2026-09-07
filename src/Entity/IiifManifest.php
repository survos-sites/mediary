<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\IiifManifestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\DataContracts\Util\MediaIdentity;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: IiifManifestRepository::class)]
#[ORM\Table(name: 'iiif_manifest')]
#[ORM\UniqueConstraint(name: 'uniq_iiif_manifest_url', columns: ['manifest_url'])]
#[ORM\Index(name: 'idx_iiif_manifest_fetched_at', columns: ['fetched_at'])]
#[ApiResource(
    shortName: 'IiifManifest',
    operations: [
        new GetCollection(),
        new Get(),
    ],
    normalizationContext: ['groups' => ['iiif.read']],
)]
class IiifManifest
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 16)]
    #[Groups(['iiif.read'])]
    public string $id;

    #[ORM\Column(name: 'manifest_url', type: Types::TEXT)]
    #[Groups(['iiif.read'])]
    public string $manifestUrl;

    #[ORM\Column(name: 'image_base', type: Types::TEXT, nullable: true)]
    #[Groups(['iiif.read'])]
    public ?string $imageBase = null;

    #[ORM\Column(name: 'thumbnail_url', type: Types::TEXT, nullable: true)]
    #[Groups(['iiif.read'])]
    public ?string $thumbnailUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['iiif.read'])]
    public ?string $label = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['iiif.read'])]
    public ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['iiif.read'])]
    public ?int $height = null;

    #[ORM\Column(name: 'manifest_json', type: Types::JSON, nullable: true)]
    #[Groups(['iiif.read'])]
    public ?array $manifestJson = null;

    #[ORM\Column(type: Types::STRING, length: 24, options: ['default' => 'reference'])]
    #[Groups(['iiif.read'])]
    public string $source = 'reference';

    #[ORM\Column(name: 'fetched_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['iiif.read'])]
    public ?\DateTimeImmutable $fetchedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['iiif.read'])]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['iiif.read'])]
    public \DateTimeImmutable $updatedAt;

    #[Groups(['iiif.read'])]
    public ?string $provider { get => $this->metadataValue('provider'); }

    #[Groups(['iiif.read'])]
    public ?string $date { get => $this->metadataValue('date'); }

    #[Groups(['iiif.read'])]
    public ?string $rights { get => $this->metadataValue('rights'); }

    #[Groups(['iiif.read'])]
    public ?string $summary { get => $this->metadataValue('summary') ?? $this->metadataValue('description'); }

    #[Groups(['iiif.read'])]
    public ?string $assetId {
        get {
            $first = $this->assets->first();
            return $first instanceof Asset ? $first->id : null;
        }
    }

    /** @var Collection<int, Asset> */
    #[ORM\OneToMany(mappedBy: 'iiifManifestEntity', targetEntity: Asset::class)]
    public Collection $assets;

    public function __construct(string $manifestUrl)
    {
        $this->id = MediaIdentity::idFromOriginalUrl($manifestUrl);
        $this->manifestUrl = $manifestUrl;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->assets = new ArrayCollection();
    }

    private function metadataValue(string $needle): ?string
    {
        $metadata = $this->manifestJson['metadata'] ?? null;
        if (!is_array($metadata)) {
            return null;
        }

        foreach ($metadata as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = $this->scalarize($entry['label'] ?? null);
            if ($label === null || strtolower($label) !== strtolower($needle)) {
                continue;
            }

            return $this->scalarize($entry['value'] ?? null);
        }

        return null;
    }

    private function scalarize(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $scalar = $this->scalarize($item);
                if ($scalar !== null) {
                    return $scalar;
                }
            }

            return null;
        }

        foreach ($value as $item) {
            $scalar = $this->scalarize($item);
            if ($scalar !== null) {
                return $scalar;
            }
        }

        return null;
    }
}
