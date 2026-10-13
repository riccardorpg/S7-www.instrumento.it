<?php

namespace App\Service;

use App\Entity\Master\Company;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * 7.1.9.8 Logo dell'agenzia.
 *
 * Come per gli allegati delle pratiche ({@see DocumentStorageService}) i file stanno fuori
 * dalla webroot — var/uploads/logos/{dbName}/ — e si servono da una rotta: la webroot
 * non è scrivibile e il logo non deve essere indovinabile da fuori.
 */
class CompanyLogoService
{
    /**
     * Estensione da usare sul file, dal MIME rilevato dal contenuto. Quali formati sono ammessi
     * (e fino a che dimensione) lo decide il validator del form (config/validator/Model/CompanyLogo.yml).
     */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly ParameterBagInterface $params)
    {
    }

    /** Percorso assoluto del logo, null se l'agenzia non ne ha uno. */
    public function absolutePath(Company $company): ?string
    {
        $logo = (string) $company->getLogo();
        if ($logo === '' || !$this->isSafeName($logo)) {
            return null;
        }

        $path = $this->tenantDir($company) . '/' . $logo;

        return is_file($path) ? $path : null;
    }

    /**
     * Salva il nuovo logo e cancella il precedente. Ritorna il nome del file da
     * mettere su Company::$logo.
     */
    public function store(Company $company, UploadedFile $file): string
    {
        // Il file precedente va letto prima di toccare l'entità, altrimenti resta orfano.
        $previous = $this->absolutePath($company);

        $dir = $this->tenantDir($company);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossibile creare la cartella di destinazione del logo.');
        }

        $extension = self::ALLOWED[(string) $file->getMimeType()] ?? 'png';
        // Nome casuale: il logo si serve da una rotta e il nome non deve dire nulla.
        $name = bin2hex(random_bytes(8)) . '.' . $extension;
        $file->move($dir, $name);

        if ($previous !== null) {
            @unlink($previous);
        }

        return $name;
    }

    /** Cancella il file del logo corrente, se c'è. Non tocca l'entità. */
    public function delete(Company $company): void
    {
        $path = $this->absolutePath($company);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function tenantDir(Company $company): string
    {
        return rtrim((string) $this->params->get('kernel.project_dir'), '/\\')
            . '/var/uploads/logos/' . (string) $company->getDbName();
    }

    /** Il nome viene dal DB, ma finisce in un percorso: niente separatori né risalite. */
    private function isSafeName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]+$/', $name) === 1 && !str_contains($name, '..');
    }
}
