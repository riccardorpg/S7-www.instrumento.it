<?php

namespace App\Service;

use App\Entity\Slave\Practice;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filtri delle liste (CONVENZIONI 7): un metodo statico per lista, che legge i filtri dalla
 * request e li conserva in sessione, così tornando su una lista si ritrovano gli stessi criteri.
 *
 * - In sessione stanno sotto "<route>_filters", la pagina sotto "<route>_page".
 * - Il form dei filtri invia sempre il marcatore "filterSet": se c'è, i filtri arrivano tutti
 *   dalla request (anche quelli svuotati, così un filtro tolto non torna dalla sessione);
 *   se manca (link del menu, ritorno da una scheda) valgono quelli salvati.
 * - L'ordinamento viaggia nei filtri come sortField / sortOrder: il repository lo applica in
 *   coda al DQL solo se il campo è fra quelli ammessi.
 * - Gli intervalli di date sono una stringa sola "gg/mm/AAAA - gg/mm/AAAA" (daterangepicker):
 *   dateRange() la spezza in inizio e fine giornata.
 * - La rotta remove_filter (SessionController) azzera filtri e pagina di una lista.
 */
class FilterService
{
    public const MARKER = 'filterSet';

    /** Chiave di sessione della scheda (stato) attiva nell'elenco pratiche dell'agenzia. */
    public const PRACTICE_TAB = 'agency_practices_tab';

    /** Chiave di sessione della scheda (stato) attiva nell'elenco pratiche del notaio. */
    public const NOTARY_PRACTICE_TAB = 'notary_practices_tab';

    // ================= liste dell'amministratore =================

    public static function clientFilter(Request $request): array
    {
        $filters = self::read($request, 'admin_clients', [
            'code' => 'f_code',
            'name' => 'f_name',
            'type' => 'f_type',       // id multipli, separati da virgola
            'license' => 'f_license', // id multipli, separati da virgola
            'expiry' => 'f_expiry',   // "gg/mm/AAAA - gg/mm/AAAA"
        ]);
        [$filters['expiryStart'], $filters['expiryEnd']] = self::dateRange($filters['expiry']);

        return $filters;
    }

    public static function licenseFilter(Request $request): array
    {
        $filters = self::read($request, 'admin_licenses', [
            'type' => 'f_type',       // id multipli, separati da virgola
            'company' => 'f_company',
            'created' => 'f_created', // "gg/mm/AAAA - gg/mm/AAAA"
            'started' => 'f_started', // "gg/mm/AAAA - gg/mm/AAAA"
            'expiry' => 'f_expiry',   // "gg/mm/AAAA - gg/mm/AAAA"
        ]);
        [$filters['createdStart'], $filters['createdEnd']] = self::dateRange($filters['created']);
        [$filters['startedStart'], $filters['startedEnd']] = self::dateRange($filters['started']);
        [$filters['expiryStart'], $filters['expiryEnd']] = self::dateRange($filters['expiry']);

        return $filters;
    }

    public static function notaryFilter(Request $request): array
    {
        return self::read($request, 'admin_notaries', [
            'q' => 'f_q',
            'email' => 'f_email',
            'agency' => 'f_agency',
        ]);
    }

    public static function externalAccountFilter(Request $request): array
    {
        return self::read($request, 'admin_external_accounts', [
            'name' => 'f_name',
            'surname' => 'f_surname',
            'email' => 'f_email',
            'phone' => 'f_phone',
            'company' => 'f_company', // id multipli, separati da virgola
        ]);
    }

    // ================= liste dell'agenzia =================

    public static function customerFilter(Request $request): array
    {
        return self::read($request, 'agency_customers', [
            'name' => 'f_name',
            'surname' => 'f_surname',
            'fiscal' => 'f_fiscal',
            'address' => 'f_address',
        ]);
    }

    public static function practiceFilter(Request $request): array
    {
        $filters = self::read($request, 'agency_practices', [
            'mortgage' => 'f_mortgage',
            'seller' => 'f_seller',
            'buyer' => 'f_buyer',
            'number' => 'f_number',
            'address' => 'f_address',
            'created' => 'f_created', // "gg/mm/AAAA - gg/mm/AAAA"
            'mark' => 'f_mark',       // id dei contrassegni separati da virgola, "none" = senza contrassegno
            'tag' => 'f_tag',         // id dei tag separati da virgola: pratiche con almeno uno
        ]);
        [$filters['createdStart'], $filters['createdEnd']] = self::dateRange($filters['created']);
        // Lo stato è la scheda dell'elenco, in sessione (AgencyPracticeController::agencyPracticeTab), non un filtro.
        $tab = (string) $request->getSession()->get(self::PRACTICE_TAB, Practice::STATUS_OPEN);
        $filters['status'] = isset(Practice::STATUSES[$tab]) ? $tab : Practice::STATUS_OPEN;

        return $filters;
    }

    public static function staffFilter(Request $request): array
    {
        return self::read($request, 'agency_staff', [
            'name' => 'f_name',
            'surname' => 'f_surname',
            'email' => 'f_email',
            'active' => 'f_active',   // '' tutti, '1' attivi, '0' sospesi
            'role' => 'f_role',       // ruoli multipli, separati da virgola
        ]);
    }

    public static function documentTypeFilter(Request $request): array
    {
        return self::read($request, 'agency_document_types', ['value' => 'f_value']);
    }

    /** Contrassegni e tag hanno lo stesso filtro: cambia solo la lista. */
    public static function colorValueFilter(Request $request, string $route): array
    {
        return self::read($request, $route, ['value' => 'f_value']);
    }

    // ================= liste del notaio =================

    public static function notaryPracticeFilter(Request $request): array
    {
        $filters = self::read($request, 'notary_practices', [
            'q' => 'f_q',             // codice della pratica
            'buyer' => 'f_buyer',
            'seller' => 'f_seller',
            'address' => 'f_address',
        ]);
        // Lo stato è la scheda dell'elenco, in sessione (NotaryController::notaryPracticeTab), non un filtro.
        $tab = (string) $request->getSession()->get(self::NOTARY_PRACTICE_TAB, Practice::STATUS_OPEN);
        $filters['status'] = isset(Practice::STATUSES[$tab]) ? $tab : Practice::STATUS_OPEN;

        return $filters;
    }

    // ================= comuni =================

    /**
     * Pagina corrente della lista: dalla request se c'è, 1 quando si è appena filtrato,
     * altrimenti quella salvata.
     */
    public static function page(Request $request, string $route): int
    {
        $session = $request->getSession();
        if ($request->query->has('page')) {
            $page = max(1, $request->query->getInt('page', 1));
        } elseif ($request->query->has(self::MARKER)) {
            $page = 1;
        } else {
            $page = max(1, (int) $session->get($route . '_page', 1));
        }
        $session->set($route . '_page', $page);

        return $page;
    }

    /** Azzera filtri e pagina di una lista (rotta remove_filter). */
    public static function clear(Request $request, string $route): void
    {
        $request->getSession()->remove($route . '_filters');
        $request->getSession()->remove($route . '_page');
    }

    /**
     * "gg/mm/AAAA - gg/mm/AAAA" => [inizio giornata, fine giornata]; estremi mancanti o non
     * validi restano null, così un filtro storto non fa fallire la pagina.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    public static function dateRange(string $value): array
    {
        $parts = array_map('trim', explode(' - ', $value, 2));
        $start = self::date($parts[0] ?? '');
        $end = self::date($parts[1] ?? '');

        return [$start?->setTime(0, 0), $end?->setTime(23, 59, 59)];
    }

    /**
     * @param array<string, string> $fields chiave del filtro => nome del parametro della request
     *
     * @return array<string, string> i filtri, più sortField e sortOrder
     */
    private static function read(Request $request, string $route, array $fields): array
    {
        $session = $request->getSession();
        $fields += ['sortField' => 'sortField', 'sortOrder' => 'sortOrder'];

        if ($request->query->has(self::MARKER)) {
            $filters = [];
            foreach ($fields as $key => $param) {
                $filters[$key] = trim((string) $request->query->get($param, ''));
            }
            $filters['sortOrder'] = strtolower($filters['sortOrder']) === 'desc' ? 'desc' : ($filters['sortOrder'] === '' ? '' : 'asc');
            $session->set($route . '_filters', $filters);

            return $filters;
        }

        $saved = (array) $session->get($route . '_filters', []);
        $filters = [];
        foreach ($fields as $key => $param) {
            $filters[$key] = (string) ($saved[$key] ?? '');
        }

        return $filters;
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $value);

        return $date !== false && \DateTimeImmutable::getLastErrors() === false ? $date : null;
    }
}
