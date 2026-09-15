<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\StorageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use function Symfony\Component\String\u;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
#[ORM\Entity(repositoryClass: StorageRepository::class)]
#[ApiResource(
    operations:
    [
        new Get(
            uriTemplate: '/storages/{id}',
            requirements: ['id' => '.+'] // Allow any character including dots
        ),
        new GetCollection(),
    ]
)]
class Storage implements \Stringable
{
    #[ORM\Column(length: 255, nullable: true)]
    private(set) ?string $adapter = null;

    /**
     * @var Collection<int, File>
     */
    #[ORM\OneToMany(targetEntity: File::class, mappedBy: 'storage', orphanRemoval: true)]
    private Collection $files;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $root = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private(set) ?string $id = null
    )
    {
        $this->files = new ArrayCollection();
    }

    static public function calcCode(string $zoneId): string {
        return $zoneId; // str_replace('.', '-', $zoneId);
    }

    public function addFile(File $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->storage = $this;
        }

        return $this;
    }

    public function removeFile(File $file): static
    {
        if ($this->files->removeElement($file)) {
            // set the owning side to null (unless already changed)
            if ($file->storage === $this) {
                $file->storage = $this;
            }
        }

        return $this;
    }

    public function __toString()
    {
        return (string)$this->id;
    }
}
