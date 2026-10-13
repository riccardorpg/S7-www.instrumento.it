<?php

namespace App\Entity\Master;

use App\Entity\Trait\IdTrait;
use App\Repository\Master\SpaceExtraRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Catalogo dei pacchetti di spazio aggiuntivo assegnabili ai clienti (Company::$spaceExtra, uno per cliente).
 * I record li crea la migration Version20261013100200 (master).
 */
#[ORM\Entity(repositoryClass: SpaceExtraRepository::class)]
#[ORM\Table(name: 'eb_m_space_extra')]
class SpaceExtra
{
    use IdTrait;

    #[ORM\Column(name: 'type', type: 'string', length: 20, unique: true)]
    private ?string $type = null;

    /** Spazio aggiunto dal pacchetto, in MB. */
    #[ORM\Column(name: 'space', type: 'integer')]
    private int $space = 0;

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSpace(): int
    {
        return $this->space;
    }

    public function setSpace(int $space): static
    {
        $this->space = $space;

        return $this;
    }

    public function getLabel(): string
    {
        return ucfirst((string) $this->type);
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }
}
