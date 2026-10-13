<?php

namespace App\Entity\Slave;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\Slave\PracticeDocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * 12.3.2.1 Riga documentale di una pratica: un tipo di documento (catalogo 13.1) da
 * produrre per questa pratica, con il suo stato (12.3.2.3) e gli allegati caricati
 * (12.3.2.5). Le righe nascono alla creazione della pratica dai tipi attivi, filtrati
 * in base al mutuo.
 */
#[ORM\Entity(repositoryClass: PracticeDocumentRepository::class)]
#[ORM\Table(name: 'eb_s_practice_document')]
#[ORM\UniqueConstraint(name: 'uniq_practice_document_type', columns: ['practice_id', 'document_type_id'])]
#[ORM\HasLifecycleCallbacks]
class PracticeDocument
{
    use IdTrait;
    use TimestampsTrait;

    // 12.3.2.3 Stato del documento.
    public const STATUS_TO_UPLOAD = 'to_upload';
    public const STATUS_TO_VERIFY = 'to_verify';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_NOT_REQUIRED = 'not_required';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_TO_UPLOAD => 'Da caricare',
        self::STATUS_TO_VERIFY => 'Da verificare',
        self::STATUS_VERIFIED => 'Verificato',
        self::STATUS_NOT_REQUIRED => 'Non necessario',
    ];

    #[ORM\ManyToOne(targetEntity: Practice::class, inversedBy: 'practiceDocuments')]
    #[ORM\JoinColumn(name: 'practice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Practice $practice = null;

    /** Tipo dal catalogo dell'agenzia. RESTRICT: il tipo usato non è eliminabile (13.1.5). */
    #[ORM\ManyToOne(targetEntity: DocumentType::class)]
    #[ORM\JoinColumn(name: 'document_type_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?DocumentType $documentType = null;

    /** Etichetta congelata: resta leggibile anche se il tipo viene rinominato o è fuori catalogo. */
    #[ORM\Column(name: 'label', type: 'string', length: 190)]
    private string $label = '';

    /** 12.3.2.2 Mostra/Nascondi: le righe nascoste non sono richieste per questa pratica. */
    #[ORM\Column(name: 'visible', type: 'boolean', options: ['default' => true])]
    private bool $visible = true;

    #[ORM\Column(name: 'status', type: 'string', length: 20)]
    private string $status = self::STATUS_TO_UPLOAD;

    /** Ordine di comparsa: copiato dalla priorità del tipo (13.1.1.1) alla creazione. */
    #[ORM\Column(name: 'priority', type: 'integer', options: ['default' => 0])]
    private int $priority = 0;

    /** @var Collection<int, Document> */
    #[ORM\OneToMany(mappedBy: 'practiceDocument', targetEntity: Document::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $documents;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }

    public function getPractice(): ?Practice
    {
        return $this->practice;
    }

    public function setPractice(?Practice $practice): static
    {
        $this->practice = $practice;

        return $this;
    }

    public function getDocumentType(): ?DocumentType
    {
        return $this->documentType;
    }

    public function setDocumentType(?DocumentType $documentType): static
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (!isset(self::STATUSES[$status])) {
            throw new \InvalidArgumentException('Stato documento non valido: ' . $status);
        }

        $this->status = $status;

        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
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

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setPracticeDocument($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document) && $document->getPracticeDocument() === $this) {
            $document->setPracticeDocument(null);
        }

        return $this;
    }

    public function hasFiles(): bool
    {
        return !$this->documents->isEmpty();
    }

    /**
     * Riga rimasta senza allegati: torna "da caricare", perché non c'è più niente da
     * verificare. Da chiamare dopo l'eliminazione di un allegato. "Non necessario" è una
     * scelta esplicita e non viene toccata.
     */
    public function resetStatusIfEmpty(): static
    {
        if (!$this->hasFiles() && in_array($this->status, [self::STATUS_VERIFIED, self::STATUS_TO_VERIFY], true)) {
            $this->status = self::STATUS_TO_UPLOAD;
        }

        return $this;
    }

    /** Somma in byte degli allegati di questa riga (12.1.8). */
    public function getSizeBytes(): int
    {
        $total = 0;
        foreach ($this->documents as $document) {
            $total += (int) $document->getSizeBytes();
        }

        return $total;
    }

    /** Ultimo aggiornamento fra gli allegati (12.3.2.5.2). */
    public function getLastUpdate(): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($this->documents as $document) {
            $date = $document->getUpdatedAt() ?? $document->getCreatedAt();
            if ($date !== null && ($last === null || $date > $last)) {
                $last = $date;
            }
        }

        return $last;
    }

    public function __toString(): string
    {
        return (string) $this->label;
    }
}
