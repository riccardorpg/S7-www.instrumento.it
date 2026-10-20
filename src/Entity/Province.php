<?php

namespace App\Entity;

use App\Entity\Trait\IdTrait;
use App\Repository\ProvinceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProvinceRepository::class)]
#[ORM\Table(name: 'eb_province')]
class Province
{
    use IdTrait;

    #[ORM\Column(name: 'name', type: 'string', length: 191)]
    private ?string $name = null;

    /** Sigla provinciale a 2 lettere (es. "MI"). */
    #[ORM\Column(name: 'sign', type: 'string', length: 8)]
    private ?string $sign = null;

    #[ORM\ManyToOne(targetEntity: Region::class)]
    #[ORM\JoinColumn(name: 'region_id', referencedColumnName: 'id', nullable: true)]
    private ?Region $region = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSign(): ?string
    {
        return $this->sign;
    }

    public function setSign(?string $sign): static
    {
        $this->sign = $sign;

        return $this;
    }

    public function getRegion(): ?Region
    {
        return $this->region;
    }

    public function setRegion(?Region $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name . ($this->sign ? ' (' . $this->sign . ')' : '');
    }
}
