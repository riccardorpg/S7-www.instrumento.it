<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Practice;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Salvataggio/lettura dei file allegati alle pratiche (practices).
 * I file vivono fuori dalla webroot: var/uploads/practices/{companyId}/{practiceId}/...
 * `storagePath` sul Documento è relativo alla cartella dell'agenzia ({companyId}).
 */
class DocumentStorageService
{
    public function __construct(private readonly ParameterBagInterface $params)
    {
    }

    /** Base assoluta di tutti gli upload pratiche. */
    private function root(): string
    {
        return rtrim((string) $this->params->get('kernel.project_dir'), '/\\') . '/var/uploads/practices';
    }

    /** Cartella assoluta dell'agenzia. */
    public function companyDir(Company $company): string
    {
        return $this->root() . '/' . (int) $company->getId();
    }

    /** Percorso assoluto di un file a partire dal suo storagePath relativo. */
    public function absolutePath(Company $company, string $relativePath): string
    {
        return $this->companyDir($company) . '/' . ltrim($relativePath, '/\\');
    }

    /**
     * MIME degli allegati (solo PDF), per l'anteprima inline. Le regole sul file caricato
     * (estensione .pdf e contenuto PDF) stanno nel validator del form
     * (config/validator/Model/PracticeDocumentUpload.yml): qui resta solo il salvataggio.
     */
    public const ALLOWED_MIME = 'application/pdf';

    /**
     * Salva l'UploadedFile per la pratica. Ritorna i metadati da persistere sul Documento.
     *
     * $displayName: nome scelto a mano per l'allegato. Se valorizzato il file NON conserva
     * il nome originale: prende questo nome più l'estensione del file caricato (pdf se
     * il file non ne ha una). È il nome mostrato nelle liste e usato nel download.
     *
     * @return array{path: string, name: string, mime: string, size: int}
     */
    public function store(Company $company, Practice $practice, UploadedFile $file, ?string $displayName = null): array
    {
        $original = $this->fileName($file, $displayName);
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', strtolower($original)) ?: 'allegato';
        $relative = $practice->getId() . '/' . bin2hex(random_bytes(8)) . '_' . $safe;
        $absDir = $this->companyDir($company) . '/' . $practice->getId();
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            throw new \RuntimeException('Impossibile creare la cartella di destinazione.');
        }

        // Il MIME va letto prima dello spostamento, e dal contenuto: quello dichiarato
        // dal browser è manipolabile.
        try {
            $mime = (string) $file->getMimeType();
        } catch (\Throwable) {
            $mime = (string) $file->getClientMimeType();
        }

        $file->move($absDir, basename($relative));

        return [
            'path' => $relative,
            'name' => $original,
            'mime' => $mime ?: 'application/octet-stream',
            'size' => (int) @filesize($this->absolutePath($company, $relative)),
        ];
    }

    /**
     * Nome del file da conservare: quello scelto nel form (più l'estensione del file
     * caricato), altrimenti il nome originale così come arriva dal browser.
     */
    private function fileName(UploadedFile $file, ?string $displayName): string
    {
        $original = (string) $file->getClientOriginalName();
        $displayName = trim((string) $displayName);
        if ($displayName === '') {
            return $original !== '' ? $original : 'allegato';
        }

        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = strtolower((string) $file->guessExtension()) ?: 'pdf';
        }

        // Se il nome scelto già finisce con l'estensione giusta non la raddoppiamo.
        if (str_ends_with(mb_strtolower($displayName), '.' . $extension)) {
            return $displayName;
        }

        return $displayName . '.' . $extension;
    }

    /** Elimina fisicamente il file (best effort). */
    public function delete(Company $company, ?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }
        $abs = $this->absolutePath($company, $relativePath);
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}
