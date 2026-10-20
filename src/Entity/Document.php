<?php

namespace App\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\DocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * 12.3.2.5 Allegato di una riga documentale della pratica.
 * Dal punto 12 gli allegati stanno sotto una {@see PracticeDocument} (il tipo di
 * documento previsto per la pratica), non più direttamente sotto la pratica: lo stesso
 * tipo può avere più file.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'eb_document')]
#[ORM\HasLifecycleCallbacks]
class Document
{
    use IdTrait;
    use TimestampsTrait;

    #[ORM\ManyToOne(targetEntity: PracticeDocument::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(name: 'practice_document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PracticeDocument $practiceDocument = null;

    /** 12.3.2.5.1 Nome dell'allegato (di default il nome del file caricato). */
    #[ORM\Column(name: 'name', type: 'string', length: 190)]
    private string $name = '';

    #[ORM\Column(name: 'original_filename', type: 'string', length: 255, nullable: true)]
    private ?string $originalFilename = null;

    /** Percorso relativo del file salvato. */
    #[ORM\Column(name: 'storage_path', type: 'string', length: 255, nullable: true)]
    private ?string $storagePath = null;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 120, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(name: 'size_bytes', type: 'bigint', nullable: true)]
    private ?int $sizeBytes = null;

    /** 12.3.2.5.3 / 12.2.6.2 Note dell'agente (agenzia). */
    #[ORM\Column(name: 'agent_note', type: 'text', nullable: true)]
    private ?string $agentNote = null;

    /** 12.3.2.5.4 Note del notaio. */
    #[ORM\Column(name: 'notary_note', type: 'text', nullable: true)]
    private ?string $notaryNote = null;

    public function getPracticeDocument(): ?PracticeDocument
    {
        return $this->practiceDocument;
    }

    public function setPracticeDocument(?PracticeDocument $practiceDocument): static
    {
        $this->practiceDocument = $practiceDocument;

        return $this;
    }

    /** Scorciatoia: la pratica a cui appartiene l'allegato. */
    public function getPractice(): ?Practice
    {
        return $this->practiceDocument?->getPractice();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(?string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function setStoragePath(?string $storagePath): static
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(?int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getAgentNote(): ?string
    {
        return $this->agentNote;
    }

    public function setAgentNote(?string $agentNote): static
    {
        $this->agentNote = $agentNote;

        return $this;
    }

    public function getNotaryNote(): ?string
    {
        return $this->notaryNote;
    }

    public function setNotaryNote(?string $notaryNote): static
    {
        $this->notaryNote = $notaryNote;

        return $this;
    }

    public function hasFile(): bool
    {
        return $this->storagePath !== null && $this->storagePath !== '';
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
