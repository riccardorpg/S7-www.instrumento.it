<?php

namespace App\Entity\Master;

use App\Entity\Trait\IdTrait;
use App\Repository\Master\LicenseTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Catalogo dei tipi di licenza (demo, base, ...) con lo spazio compreso.
 * I record li crea la migration Version20261013100200 (master).
 */
#[ORM\Entity(repositoryClass: LicenseTypeRepository::class)]
#[ORM\Table(name: 'eb_m_license_type')]
class LicenseType
{
    use IdTrait;

    public const DEMO = 'demo';
    public const BASE = 'base';

    #[ORM\Column(name: 'type', type: 'string', length: 20, unique: true)]
    private ?string $type = null;

    /** Spazio compreso nella licenza, in MB. */
    #[ORM\Column(name: 'space_included', type: 'integer')]
    private int $spaceIncluded = 0;

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSpaceIncluded(): int
    {
        return $this->spaceIncluded;
    }

    public function setSpaceIncluded(int $spaceIncluded): static
    {
        $this->spaceIncluded = $spaceIncluded;

        return $this;
    }

    public function getLabel(): string
    {
        return ucfirst((string) $this->type);
    }

    public function isDemo(): bool
    {
        return $this->type === self::DEMO;
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }
}
