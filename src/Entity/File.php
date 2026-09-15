<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Elasticsearch\Filter\OrderFilter;
use App\Repository\FileRepository;
use App\Workflow\IFileWorkflow;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\FieldBundle\Entity\RouteParametersInterface;
use Survos\Tree\Traits\TreeTrait;
use Survos\Tree\TreeInterface;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Serializer\Filter\PropertyFilter;

#[ApiResource(
    normalizationContext: ['groups' => ['Default', 'jstree', 'minimum', 'marking', 'transitions', 'rp']],
    denormalizationContext: ['groups' => ["Default", "minimum", "browse"]],
)]
#[ApiFilter(OrderFilter::class, properties: ['marking', 'org', 'shortName', 'fullName'], arguments: ['orderParameterName' => 'order'])]
#[ApiFilter(SearchFilter::class, properties: ['code' => 'exact', 'name' => 'partial', 'isDir' => 'exact'])]
#[Gedmo\Tree(type: "nested")]
#[ORM\Entity(repositoryClass: FileRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_STORAGE_PATH', fields: ['storage', 'path'])]
#[RouteIdentity(field: 'id')]
class File implements \Stringable, TreeInterface, MarkingInterface,RouteParametersInterface
{
    use TreeTrait;
    use MarkingTrait;
    use RouteIdentityTrait;

    #[ORM\Id]
    #[ORM\Column()]
    #[Groups(['minimum', 'search', 'jstree'])]
    private(set) string $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['minimum', 'search', 'jstree'])]
    public string $name;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    public ?\DateTimeInterface $lastModified = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['file.read'])]
    public ?int $fileSize = null;

    #[Groups(['file.read'])]
    public int $listingCount { get => $this->dirCount + $this->fileCount; }

    #[ORM\Column(nullable: true)]
    #[Groups(['file.read'])]
    public ?int $dirCount = null;
    #[ORM\Column(nullable: true)]
    #[Groups(['file.read'])]
    public ?int $fileCount = null;

    #[Groups(['file.read'])]
    public string $type { get => $this->isDir ? 'dir' : 'file'; }


//    private $children;
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'files')]
        #[ORM\JoinColumn(nullable: false)]
        public ?Storage $storage = null,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        #[Groups(['file.read'])]
        private(set) ?string  $path = null,
        #[ORM\Column(type: 'boolean')]
        #[Groups(['file.read'])]
        private(set) bool $isDir = false,
        #[ORM\Column(type: 'boolean')]
        #[Groups(['file.read'])]
        public bool $isPublic = true,
    )
    {
        $this->children = new ArrayCollection();
        if ($storage) {
            $this->storage->addFile($this);
        }
        $this->marking = $this->isDir ? IFileWorkflow::PLACE_NEW_DIR : IFileWorkflow::PLACE_NEW_FILE;
        // unfortunately, these codes are different than the filenames!
        $this->id = self::calcCode($this->storageId, $this->path);
        if ($this->isDir) {
            $this->dirCount = 0;
            $this->fileCount = 0;
        }
    }

    static public function calcCode(string|Storage $storageId, string $path)
    {
        return hash('xxh3', (is_string($storageId) ? $storageId : $storageId->id) . $path);
    }

    public string $storageId { get => $this->storage->id; }


//    public function getId(): ?int
//    {
//        return $this->id;
//    }

    public function getId(): ?string
    {
        return $this->id;
    }



    public function __toString(): string
    {
        return (string)$this->name;
    }

    public function getExtension(): ?string
    {
        return pathinfo($this->name, PATHINFO_EXTENSION);
    }

}
