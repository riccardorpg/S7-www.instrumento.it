<?php

namespace App\Form\Model;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * 7.1.9.8 Logo dell'agenzia: il file caricato. Su Company resta solo il nome del file,
 * che dà CompanyLogoService dopo la validazione.
 */
class CompanyLogo
{
    private ?UploadedFile $file = null;

    public function getFile(): ?UploadedFile
    {
        return $this->file;
    }

    public function setFile(?UploadedFile $file): static
    {
        $this->file = $file;

        return $this;
    }
}
