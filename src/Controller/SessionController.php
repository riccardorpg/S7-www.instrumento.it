<?php

namespace App\Controller;

use App\Service\FilterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Preferenze d'interfaccia salvate in sessione, condivise da tutte le aree (stile
 * Tillomeditalia). Sta fuori da /amministratore, /notaio e /agenzia perché la usano
 * tutti i ruoli: basta essere autenticati.
 *
 * REMEMBERED e non FULLY: con "Resta collegato" la sessione non è "piena" e la chiamata
 * verrebbe respinta, perdendo la tab attiva senza nessun segnale.
 */
#[Route('/sessione')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
class SessionController extends AbstractController
{
    /**
     * Salva la tab attiva di una pagina. La chiave è "{route}_tab"; il ripristino
     * avviene lato Twig al load (components/js/tab_session_js.html.twig).
     */
    #[Route('/tab', name: 'session_update_tab', methods: ['POST'])]
    public function sessionUpdateTab(Request $request): JsonResponse
    {
        $path = (string) $request->request->get('path');
        $tab = (string) $request->request->get('tab');
        if ($path !== '') {
            $request->getSession()->set($path . '_tab', $tab);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * "Escludi filtro" delle liste (CONVENZIONI 7): azzera filtri e pagina salvati in sessione
     * da FilterService e torna alla lista. Solo le liste con filtri: niente redirect arbitrari.
     */
    #[Route('/rimuovi-filtro/{route}', name: 'remove_filter', methods: ['GET'])]
    public function removeFilter(string $route, Request $request): RedirectResponse
    {
        if (!in_array($route, self::FILTERED_LISTS, true)) {
            throw $this->createNotFoundException();
        }
        FilterService::clear($request, $route);

        return $this->redirectToRoute($route);
    }

    /** Liste con i filtri in sessione (FilterService). */
    private const FILTERED_LISTS = [
        'admin_clients', 'admin_licenses', 'admin_notaries', 'admin_external_accounts',
        'agency_customers', 'agency_practices', 'agency_staff',
        'agency_document_types', 'agency_practice_marks', 'agency_practice_tags',
        'notary_practices',
    ];
}
