<?php

namespace App\Twig\Extension;

use App\Entity\Master\Company;
use App\Repository\Master\DemoRequestRepository;
use App\Service\CompanyService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Funzioni Twig applicative.
 * current_company(): l'agenzia (Company) selezionata in sessione, per mostrarla
 * nel menu laterale del notaio (stile "azienda attiva" di venanzieffe).
 * pending_demo_requests(): contatore dell'allarme 6.1.1 per il badge nel menu admin.
 */
class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly CompanyService $companyService,
        private readonly DemoRequestRepository $demoRequests,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_company', $this->currentCompany(...)),
            new TwigFunction('pending_demo_requests', $this->pendingDemoRequests(...)),
        ];
    }

    public function currentCompany(): ?Company
    {
        return $this->companyService->getCurrentCompany();
    }

    public function pendingDemoRequests(): int
    {
        return $this->demoRequests->countPending();
    }
}
