<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Practice;
use App\Entity\User;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Practice>
 */
class PracticeRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Practice::class);
    }

    /**
     * 12.1 Elenco pratiche dell'agenzia filtrato (filtri da FilterService::practiceFilter): lo stato
     * è la scheda attiva dell'elenco ($filters['status']).
     * 12.3.3 Con $onlyStaffId si vedono solo le pratiche in cui quell'utente è abilitato.
     */
    public function queryAgencyFiltered(Company $company, array $filters, ?int $onlyStaffId = null): Query
    {
        $qb = $this->agencyFilteredQb($company, $filters, $onlyStaffId)
            ->addSelect('s', 'b', 'm');
        if (isset(Practice::STATUSES[$filters['status']])) {
            $qb->andWhere('p.status = :status')->setParameter('status', $filters['status']);
        }

        // Ordinamento per tag: una pratica può averne più d'uno, conta il primo in ordine alfabetico.
        $qb->addSelect('(SELECT MIN(st.value) FROM ' . Practice::class . ' sp JOIN sp.tags st WHERE sp.id = p.id) AS HIDDEN tagSort');
        $this->applySort($qb, $filters, ['p.createdAt', 'p.mortgage', 'p.number', 'p.address', 'm.value', 'tagSort'], 'p.number', 'asc');

        return $qb->getQuery();
    }

    /**
     * Quante pratiche per stato con gli stessi filtri dell'elenco (stato escluso): i contatori
     * delle schede.
     *
     * @return array<string, int> stato => pratiche (tutti gli stati, anche a zero)
     */
    public function countAgencyByStatus(Company $company, array $filters, ?int $onlyStaffId = null): array
    {
        $rows = $this->agencyFilteredQb($company, $filters, $onlyStaffId)
            ->select('p.status AS status, COUNT(DISTINCT p.id) AS qty')
            ->groupBy('p.status')
            ->getQuery()->getArrayResult();

        $counts = array_fill_keys(array_keys(Practice::STATUSES), 0);
        foreach ($rows as $r) {
            if (isset($counts[$r['status']])) {
                $counts[$r['status']] = (int) $r['qty'];
            }
        }

        return $counts;
    }

    /** Le condizioni comuni a elenco e contatori: tutti i filtri tranne lo stato. */
    private function agencyFilteredQb(Company $company, array $filters, ?int $onlyStaffId): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.seller', 's')
            ->leftJoin('p.buyer', 'b')
            ->leftJoin('p.mark', 'm')
            ->where('p.company = :company');
        $params = ['company' => $company];

        if ($onlyStaffId !== null) {
            $qb->leftJoin('p.staff', 'ps')->andWhere('ps.id = :me');
            $params['me'] = $onlyStaffId;
        }
        if ($filters['createdStart'] !== null) {
            $qb->andWhere('p.createdAt >= :createdStart');
            $params['createdStart'] = $filters['createdStart'];
        }
        if ($filters['createdEnd'] !== null) {
            $qb->andWhere('p.createdAt <= :createdEnd');
            $params['createdEnd'] = $filters['createdEnd'];
        }
        if ($filters['mortgage'] !== '') {
            $qb->andWhere('p.mortgage = :mortgage');
            $params['mortgage'] = $filters['mortgage'] === '1';
        }
        if ($filters['seller'] !== '') {
            $qb->andWhere("CONCAT(COALESCE(s.name, ''), ' ', COALESCE(s.surname, '')) LIKE :seller");
            $params['seller'] = '%' . $filters['seller'] . '%';
        }
        if ($filters['buyer'] !== '') {
            $qb->andWhere("CONCAT(COALESCE(b.name, ''), ' ', COALESCE(b.surname, '')) LIKE :buyer");
            $params['buyer'] = '%' . $filters['buyer'] . '%';
        }
        if ($filters['number'] !== '') {
            $qb->andWhere('p.number LIKE :number');
            $params['number'] = '%' . $filters['number'] . '%';
        }
        if ($filters['address'] !== '') {
            $qb->andWhere('p.address LIKE :address');
            $params['address'] = '%' . $filters['address'] . '%';
        }
        // Contrassegno: uno fra quelli scelti, oppure nessuno ("none").
        $marks = array_values(array_filter(explode(',', $filters['mark'])));
        if ($marks !== []) {
            $orX = [];
            $markIds = array_map('intval', array_diff($marks, ['none']));
            if ($markIds !== []) {
                $orX[] = 'm.id IN (:marks)';
                $params['marks'] = $markIds;
            }
            if (in_array('none', $marks, true)) {
                $orX[] = 'm.id IS NULL';
            }
            $qb->andWhere(implode(' OR ', $orX));
        }
        // Tag: le pratiche con almeno uno dei tag scelti (sottoquery, così la riga resta una per pratica).
        $tagIds = array_map('intval', array_values(array_filter(explode(',', $filters['tag']))));
        if ($tagIds !== []) {
            $qb->andWhere('p.id IN (SELECT pt.id FROM ' . Practice::class . ' pt JOIN pt.tags tt WHERE tt.id IN (:tags))');
            $params['tags'] = $tagIds;
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }

        return $qb;
    }
    /**
     * 17.1 Pratiche assegnate al notaio, di tutte le agenzie (filtri da
     * FilterService::notaryPracticeFilter). Lo stato è la scheda attiva dell'elenco ($filters['status']).
     */
    public function queryNotaryFiltered(User $notary, array $filters): Query
    {
        $qb = $this->notaryFilteredQb($notary, $filters)->addSelect('c');
        if (isset(Practice::STATUSES[$filters['status']])) {
            $qb->andWhere('p.status = :status')->setParameter('status', $filters['status']);
        }
        $this->applySort($qb, $filters, ['p.number', 'p.mortgage', 'p.address', 'p.createdAt', 'c.name'], 'p.createdAt', 'desc');

        return $qb->getQuery();
    }

    /**
     * Quante pratiche per stato con gli stessi filtri dell'elenco del notaio (stato escluso):
     * i contatori delle schede.
     *
     * @return array<string, int> stato => pratiche (tutti gli stati, anche a zero)
     */
    public function countNotaryByStatus(User $notary, array $filters): array
    {
        $rows = $this->notaryFilteredQb($notary, $filters)
            ->select('p.status AS status, COUNT(DISTINCT p.id) AS qty')
            ->groupBy('p.status')
            ->getQuery()->getArrayResult();

        $counts = array_fill_keys(array_keys(Practice::STATUSES), 0);
        foreach ($rows as $r) {
            if (isset($counts[$r['status']])) {
                $counts[$r['status']] = (int) $r['qty'];
            }
        }

        return $counts;
    }

    /**
     * 12.1.6 Prossimo codice pratica dell'agenzia: {codice agenzia}-{progressivo}/{anno}, es.
     * AG001-0001/2026. Il progressivo riparte ogni anno e segue il più alto già usato
     * dall'agenzia (non il conteggio: dopo una cancellazione un conteggio ridarebbe un codice esistente).
     */
    public function nextNumber(Company $company, ?int $year = null): string
    {
        $year ??= (int) (new \DateTimeImmutable())->format('Y');
        $prefix = mb_strtoupper((string) $company->getCode()) . '-';

        $numbers = $this->createQueryBuilder('p')
            ->select('p.number')
            ->where('p.company = :company')->setParameter('company', $company)
            ->andWhere('p.number LIKE :pattern')->setParameter('pattern', addcslashes($prefix, '%_') . '%/' . $year)
            ->getQuery()->getSingleColumnResult();

        $max = 0;
        foreach ($numbers as $number) {
            if (preg_match('#-(\d+)/\d{4}$#', (string) $number, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('%s%04d/%d', $prefix, $max + 1, $year);
    }

    /**
     * 17.1 Pratiche visibili nell'area notaio a $user: per il notaio quelle assegnate a lui, per un
     * collaboratore quelle che un suo notaio gli ha assegnato (e solo finché è nel suo staff).
     * Aggiunge le condizioni al QueryBuilder; $p è l'alias della pratica.
     */
    public static function notaryScope(QueryBuilder $qb, string $p, User $user): QueryBuilder
    {
        if ($user->isNotaryStaff()) {
            return $qb->join($p . '.notary', $p . '_nn')
                ->andWhere(':areaUser MEMBER OF ' . $p . '.notaryStaff')
                ->andWhere(':areaUser MEMBER OF ' . $p . '_nn.collaborators')
                ->setParameter('areaUser', $user);
        }

        return $qb->andWhere($p . '.notary = :areaUser')->setParameter('areaUser', $user);
    }

    /** Le condizioni comuni a elenco e contatori del notaio: tutti i filtri tranne lo stato. */
    private function notaryFilteredQb(User $notary, array $filters): QueryBuilder
    {
        // Solo le pratiche assegnate a lui (o al collaboratore), di agenzie attive.
        $qb = $this->createQueryBuilder('p')
            ->join('p.company', 'c')
            ->where('c.active = true');
        self::notaryScope($qb, 'p', $notary);
        $params = [];

        if (($filters['company'] ?? '') !== '') {
            $qb->andWhere('c.id IN (:companies)');
            $params['companies'] = array_map('intval', explode(',', $filters['company']));
        }

        // Ricerca sul codice della pratica.
        if ($filters['q'] !== '') {
            $qb->andWhere('p.number LIKE :q');
            $params['q'] = '%' . $filters['q'] . '%';
        }
        // Le parti sono clienti dell'agenzia (11): il filtro cerca su nome e cognome.
        if ($filters['buyer'] !== '') {
            $qb->leftJoin('p.buyer', 'bc')->andWhere("CONCAT(COALESCE(bc.name, ''), ' ', COALESCE(bc.surname, '')) LIKE :buyer");
            $params['buyer'] = '%' . $filters['buyer'] . '%';
        }
        if ($filters['seller'] !== '') {
            $qb->leftJoin('p.seller', 'sc')->andWhere("CONCAT(COALESCE(sc.name, ''), ' ', COALESCE(sc.surname, '')) LIKE :seller");
            $params['seller'] = '%' . $filters['seller'] . '%';
        }
        if ($filters['address'] !== '') {
            $qb->andWhere('p.address LIKE :address');
            $params['address'] = '%' . $filters['address'] . '%';
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }

        return $qb;
    }
}
