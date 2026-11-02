<?php

namespace App\Exception;

/**
 * Archivio della pratica su Aruba non riuscito (caricamento o lettura). Il messaggio è pensato
 * per l'utente: i dettagli tecnici restano nel log e nell'eccezione precedente.
 */
class PracticeArchiveException extends \RuntimeException
{
}
