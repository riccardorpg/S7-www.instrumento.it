<?php

namespace App\Entity;

use App\Entity\Trait\IdTrait;
use App\Repository\ZipRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ZipRepository::class)]
#[ORM\Table(name: 'eb_zip')]
class Zip
{
    use IdTrait;

    /** Il CAP. */
    #[ORM\Column(name: 'code', type: 'string', length: 16)]
    private ?string $code = null;

    /** @var Collection<int, City> — lato proprietario della ManyToMany. */
    #[ORM\ManyToMany(targetEntity: City::class, inversedBy: 'zips')]
    #[ORM\JoinTable(name: 'eb_join_table_city_zip')]
    private Collection $cities;

    public function __construct()
    {
        $this->cities = new ArrayCollection();
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

    /** @return Collection<int, City> */
    public function getCities(): Collection
    {
        return $this->cities;
    }

    public function addCity(City $city): static
    {
        if (!$this->cities->contains($city)) {
            $this->cities->add($city);
        }

        return $this;
    }

    public function removeCity(City $city): static
    {
        $this->cities->removeElement($city);

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->code;
    }
}
