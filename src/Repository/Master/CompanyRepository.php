<?php

namespace App\Repository\Master;

use App\Entity\Master\Company;
use App\Entity\Master\LicenseType;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Company>
 */
class CompanyRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    /**
     * 7.1 Elenco clienti filtrato (filtri da FilterService::clientFilter).
     * Lo stato è quello del cliente (attivo / sospeso); demo, scaduta e senza licenza riguardano la licenza.
     */
    public function queryAllFiltered(array $filters): Query
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.license', 'l')->addSelect('l')
            ->leftJoin('l.type', 'lt')->addSelect('lt')
            ->where('1=1');
        $params = [];

        if ($filters['code'] !== '') {
            $qb->andWhere('c.code LIKE :code');
            $params['code'] = '%' . $filters['code'] . '%';
        }
        if ($filters['name'] !== '') {
            $qb->andWhere('c.name LIKE :name');
            $params['name'] = '%' . $filters['name'] . '%';
        }
        $types = array_values(array_filter(explode(',', $filters['type'])));
        if ($types !== []) {
            $qb->andWhere('c.clientType IN (:types)');
            $params['types'] = $types;
        }
        // Licenza: id dei tipi, più "none" per i clienti senza licenza.
        $licenses = array_values(array_filter(explode(',', $filters['license'])));
        $typeIds = array_values(array_diff($licenses, ['none']));
        $orX = [];
        if ($typeIds !== []) {
            $orX[] = 'lt.id IN (:licenses)';
            $params['licenses'] = $typeIds;
        }
        if (in_array('none', $licenses, true)) {
            $orX[] = 'l.id IS NULL';
        }
        if ($orX !== []) {
            $qb->andWhere(implode(' OR ', $orX));
        }
        if ($filters['expiryStart'] !== null && $filters['expiryEnd'] !== null) {
            $qb->andWhere('l.expirationDate BETWEEN :expiryStart AND :expiryEnd');
            $params['expiryStart'] = $filters['expiryStart'];
            $params['expiryEnd'] = $filters['expiryEnd'];
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['c.name', 'c.code', 'c.clientType', 'lt.type', 'l.expirationDate', 'c.active'], 'c.name');

        return $qb->getQuery();
    }

    public function findOneByCode(string $code): ?Company
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * Clienti con licenza in scadenza entro $days giorni (incluse già scadute).
     * $demo=true → solo licenze demo (6.1.2); $demo=false → licenze non-demo (6.1.3).
     *
     * @return Company[]
     */
    public function findExpiring(bool $demo, int $days = 30): array
    {
        $limit = new \DateTimeImmutable('today +' . $days . ' days');

        $qb = $this->createQueryBuilder('c')
            ->join('c.license', 'l')->addSelect('l')
            ->join('l.type', 't')->addSelect('t')
            ->andWhere('l.expirationDate <= :limit')
            ->setParameter('limit', $limit)
            ->setParameter('demo', LicenseType::DEMO)
            ->orderBy('l.expirationDate', 'ASC');

        $qb->andWhere($demo ? 't.type = :demo' : 't.type != :demo');

        return $qb->getQuery()->getResult();
    }
}
