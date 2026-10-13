<?php

namespace App\Entity\Master;

use App\Entity\Trait\IdTrait;
use App\Repository\Master\RegionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RegionRepository::class)]
#[ORM\Table(name: 'eb_m_region')]
class Region
{
    use IdTrait;

    #[ORM\Column(name: 'name', type: 'string', length: 191)]
    private ?string $name = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
