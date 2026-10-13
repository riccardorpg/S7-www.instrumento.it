<?php

namespace App\Entity\Master;

use App\Entity\Trait\IdTrait;
use App\Repository\Master\CityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CityRepository::class)]
#[ORM\Table(name: 'eb_m_city')]
class City
{
    use IdTrait;

    #[ORM\Column(name: 'name', type: 'string', length: 191)]
    private ?string $name = null;

    #[ORM\Column(name: 'code', type: 'string', length: 32, nullable: true)]
    private ?string $code = null;

    #[ORM\ManyToOne(targetEntity: Province::class)]
    #[ORM\JoinColumn(name: 'province_id', referencedColumnName: 'id', nullable: true)]
    private ?Province $province = null;

    #[ORM\ManyToOne(targetEntity: Region::class)]
    #[ORM\JoinColumn(name: 'region_id', referencedColumnName: 'id', nullable: true)]
    private ?Region $region = null;

    /** @var Collection<int, Zip> — lato inverso della ManyToMany (owning = Zip). */
    #[ORM\ManyToMany(targetEntity: Zip::class, mappedBy: 'cities')]
    private Collection $zips;

    public function __construct()
    {
        $this->zips = new ArrayCollection();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getProvince(): ?Province
    {
        return $this->province;
    }

    public function setProvince(?Province $province): static
    {
        $this->province = $province;

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

    /** @return Collection<int, Zip> */
    public function getZips(): Collection
    {
        return $this->zips;
    }

    public function addZip(Zip $zip): static
    {
        if (!$this->zips->contains($zip)) {
            $this->zips->add($zip);
            $zip->addCity($this);
        }

        return $this;
    }

    /**
     * Stringa "id-code,id-code,…" usata dal modale città per popolare il select dei CAP.
     */
    public function getDisplayZips(): string
    {
        $parts = [];
        foreach ($this->zips as $zip) {
            $parts[] = $zip->getId() . '-' . $zip->getCode();
        }

        return implode(',', $parts);
    }

    public function __toString(): string
    {
        return $this->name . ($this->province ? ' (' . $this->province->getSign() . ')' : '');
    }
}
