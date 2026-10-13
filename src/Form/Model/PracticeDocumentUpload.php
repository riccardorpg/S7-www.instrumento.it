<?php

namespace App\Form\Model;

use App\Entity\Slave\PracticeDocument;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * 12.3.2.6 / 17.1.1.1.5.3 Nuovo allegato di una pratica (agenzia e notaio): riga documentale,
 * nome con cui salvare il file, il file e una nota. Il file non sta sull'entity Document,
 * quindi il form lavora su questo oggetto; il controller crea il Document dopo la validazione.
 * La nota diventa la nota dell'agente o quella del notaio, a seconda di chi carica.
 */
class PracticeDocumentUpload
{
    private ?PracticeDocument $practiceDocument = null;
    private string $name = '';
    private ?UploadedFile $file = null;
    private ?string $note = null;

    public function getPracticeDocument(): ?PracticeDocument
    {
        return $this->practiceDocument;
    }

    public function setPracticeDocument(?PracticeDocument $practiceDocument): static
    {
        $this->practiceDocument = $practiceDocument;

        return $this;
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

    public function getFile(): ?UploadedFile
    {
        return $this->file;
    }

    public function setFile(?UploadedFile $file): static
    {
        $this->file = $file;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }
}
