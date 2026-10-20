<?php

namespace App\Controller;

use App\Entity\City;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoint AJAX del sottosistema geo (città / CAP), lato Master.
 * Il picker (components/modals/city.html.twig + js/city_js.html.twig) li interroga.
 *
 * Sono dati di riferimento comuni, non roba da amministratori: li usa anche l'area
 * agenzia (indirizzo della pratica), quindi bastano utenti autenticati. I nomi delle
 * rotte restano quelli originali, così i template esistenti non cambiano.
 *
 * REMEMBERED e non FULLY: con "Resta collegato" la sessione è ripristinata dal cookie
 * e non è "piena", ma la pagina si apre — con FULLY queste chiamate AJAX rispondevano
 * un redirect al login e il picker città smetteva di funzionare senza dirlo.
 */
#[Route('/api/geo')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
class GeoController extends AbstractController
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    /** Ricerca comuni per prefisso (max 5). */
    #[Route('/cerca-citta', name: 'geo_cities', methods: ['POST'])]
    public function geoCities(Request $request): JsonResponse
    {
        $name = trim((string) $request->request->get('name', ''));
        $qb = $this->registry->getManager()->getRepository(City::class)->createQueryBuilder('c')
            ->leftJoin('c.province', 'p')->addSelect('p')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults(5);
        if ($name !== '') {
            $qb->andWhere('c.name LIKE :name')->setParameter('name', $name . '%');
        }

        $cities = array_map(static fn (City $c) => [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'province' => $c->getProvince()?->getSign() ?: '-',
        ], $qb->getQuery()->getResult());

        return $this->json(['success' => true, 'code' => 200, 'cities' => json_encode($cities)]);
    }

    /** CAP del comune selezionato, formato "id-code,id-code,…". */
    #[Route('/cerca-cap', name: 'geo_city_zips', methods: ['POST'])]
    public function geoCityZips(Request $request): JsonResponse
    {
        $cityId = (int) $request->request->get('cityId');
        $city = $cityId ? $this->registry->getManager()->getRepository(City::class)->find($cityId) : null;

        return $this->json([
            'success' => true,
            'code' => 200,
            'zips' => $city ? $city->getDisplayZips() : '',
        ]);
    }
}
