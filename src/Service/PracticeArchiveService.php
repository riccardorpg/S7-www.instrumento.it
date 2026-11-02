<?php

namespace App\Service;

use App\Entity\Practice;
use App\Exception\PracticeArchiveException;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 12.3.5 / 12.3.6 Archivio delle pratiche sull'Object Storage di Aruba (bucket "companies").
 *
 * All'archiviazione i documenti della pratica diventano uno ZIP (una cartella per tipologia,
 * come "Scarica documenti") caricato in {codice agenzia}/{codice pratica}.zip; solo dopo che il
 * caricamento è riuscito la pratica passa ad archiviata e i file locali si cancellano: da quel
 * momento i documenti si scaricano solo dallo ZIP ("Scarica archivio").
 *
 * Il codice dipende solo da FilesystemOperator (adapter S3 configurato in flysystem.yaml).
 */
class PracticeArchiveService
{
    public function __construct(
        private readonly FilesystemOperator $arubaStorage,
        private readonly DocumentStorageService $storage,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * ZIP con gli allegati della pratica che sono ancora sul server, una cartella per tipologia.
     * Restituisce il percorso del file temporaneo: lo cancella chi lo usa.
     */
    public function buildZip(Practice $practice): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'instrumento_') ?: throw new \RuntimeException('Impossibile creare il file temporaneo.');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossibile creare l\'archivio.');
        }

        $company = $practice->getCompany() ?? throw new \LogicException('Pratica senza agenzia.');
        $added = 0;
        $used = [];
        foreach ($practice->getPracticeDocuments() as $row) {
            foreach ($row->getDocuments() as $document) {
                if (!$document->hasFile()) {
                    continue;
                }
                $absolute = $this->storage->absolutePath($company, (string) $document->getStoragePath());
                if (!is_file($absolute)) {
                    continue;
                }
                // Una cartella per tipo di documento, così l'archivio resta leggibile; nomi doppi numerati.
                $folder = preg_replace('/[^\w\s.-]+/u', '', $row->getLabel()) ?: 'Documenti';
                $name = $folder . '/' . ($document->getOriginalFilename() ?: $document->getName());
                for ($i = 2; isset($used[mb_strtolower($name)]); ++$i) {
                    $info = pathinfo($folder . '/' . ($document->getOriginalFilename() ?: $document->getName()));
                    $name = $info['dirname'] . '/' . $info['filename'] . ' (' . $i . ')' . (isset($info['extension']) ? '.' . $info['extension'] : '');
                }
                $used[mb_strtolower($name)] = true;
                $zip->addFile($absolute, $name);
                ++$added;
            }
        }

        if ($added === 0) {
            $zip->addFromString('LEGGIMI.txt', 'La pratica ' . $practice->getNumber() . " non ha allegati.\n");
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * Archivia la pratica: carica lo ZIP su Aruba, la segna archiviata e cancella i file locali.
     * Se il caricamento non riesce la pratica resta com'era.
     *
     * @throws PracticeArchiveException
     */
    public function archive(Practice $practice): void
    {
        $company = $practice->getCompany() ?? throw new \LogicException('Pratica senza agenzia.');
        $path = $this->archivePathFor($practice);
        $zipPath = $this->buildZip($practice);

        try {
            $stream = fopen($zipPath, 'rb') ?: throw new \RuntimeException('Impossibile leggere l\'archivio.');
            try {
                $this->arubaStorage->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } catch (FilesystemException $e) {
            $this->logger->error('Archivio Aruba: caricamento non riuscito', ['practice' => $practice->getId(), 'path' => $path, 'exception' => $e]);

            throw new PracticeArchiveException('Caricamento dell\'archivio su Aruba non riuscito: la pratica non è stata archiviata. Riprova più tardi.', 0, $e);
        } finally {
            @unlink($zipPath);
        }

        // Caricato: la pratica passa ad archiviata e gli allegati non hanno più un file sul server.
        $localFiles = [];
        foreach ($practice->getPracticeDocuments() as $row) {
            foreach ($row->getDocuments() as $document) {
                if ($document->hasFile()) {
                    $localFiles[] = $this->storage->absolutePath($company, (string) $document->getStoragePath());
                    $document->setStoragePath(null);
                }
            }
        }
        $practice->setArchivePath($path)->setStatus(Practice::STATUS_ARCHIVED);
        $this->em->flush();

        // Pulizia dei file locali (best effort: il dato è già nello ZIP su Aruba).
        $fs = new Filesystem();
        $fs->remove($localFiles);
        $folder = $this->storage->companyDir($company) . '/' . $practice->getId();
        if (is_dir($folder) && (new \FilesystemIterator($folder))->valid() === false) {
            $fs->remove($folder);
        }
    }

    /**
     * Scarica lo ZIP della pratica da Aruba, in streaming (senza caricarlo in memoria).
     *
     * @throws PracticeArchiveException
     */
    public function downloadResponse(Practice $practice): StreamedResponse
    {
        $path = (string) $practice->getArchivePath();
        try {
            $stream = $this->arubaStorage->readStream($path);
        } catch (FilesystemException $e) {
            $this->logger->error('Archivio Aruba: lettura non riuscita', ['practice' => $practice->getId(), 'path' => $path, 'exception' => $e]);

            throw new PracticeArchiveException('Archivio non raggiungibile su Aruba: riprova più tardi.', 0, $e);
        }

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, basename($path)));

        return $response;
    }

    /** Percorso nel bucket: {codice agenzia}/{codice pratica}.zip, es. AG001/AG001-0001-2026.zip. */
    public function archivePathFor(Practice $practice): string
    {
        $safe = static fn (string $value) => preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?: 'x';

        return $safe(mb_strtoupper((string) $practice->getCompany()?->getCode())) . '/' . $safe((string) $practice->getNumber()) . '.zip';
    }
}
