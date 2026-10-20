<?php

namespace App\Entity;

use App\Entity\Trait\ActiveTrait;
use App\Entity\Trait\CompanyOwnedTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\DocumentTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * 13.1 Tipo di documento configurato dall'agenzia: vive nel DB dell'agenzia (slave).
 * È il catalogo da cui nascono i documenti richiesti di una pratica.
 */
#[ORM\Entity(repositoryClass: DocumentTypeRepository::class)]
#[ORM\Table(name: 'eb_document_type')]
#[ORM\HasLifecycleCallbacks]
class DocumentType implements CompanyOwnedInterface
{
    use CompanyOwnedTrait;
    use IdTrait;
    use ActiveTrait;
    use TimestampsTrait;

    /** 13.1.1.2 Valore: etichetta del tipo di documento (es. "Visura catastale"). */
    #[ORM\Column(name: 'value', type: 'string', length: 190)]
    private string $value = '';

    /**
     * 13.1.1.1 Priorità: posizione nell'elenco, 0 = primo. Gestita dal drag & drop (13.1.3),
     * non è un campo del form.
     */
    #[ORM\Column(name: 'priority', type: 'integer', options: ['default' => 0])]
    private int $priority = 0;

    /** 13.1.1.3 Mutuo: il documento serve solo alle pratiche con mutuo. */
    #[ORM\Column(name: 'mortgage', type: 'boolean', options: ['default' => false])]
    private bool $mortgage = false;

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function isMortgage(): bool
    {
        return $this->mortgage;
    }

    public function setMortgage(bool $mortgage): static
    {
        $this->mortgage = $mortgage;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
