<?php

namespace App\EventListener;

use App\Service\CompanyService;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Ad ogni request principale ri-punta la connessione 'slave' al DB dell'agenzia
 * memorizzato in sessione, PRIMA che il firewall (priority 8) provi a ricaricare
 * l'utente ROLE_AGENCY dal user_provider entity 'slave'.
 *
 * Registrato con priority 100 su kernel.request in config/services.yaml.
 */
class SlaveDatabaseSwitcherListener
{
    /** Prefissi che non richiedono contesto tenant. */
    private const SKIP_PATH_PREFIXES = [
        '/script',   // firewall http_basic
        '/_',        // profiler / wdt
        '/css',
        '/js',
        '/assets',
        '/build',
        '/images',
    ];

    public function __construct(private readonly CompanyService $companyService)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        foreach (self::SKIP_PATH_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return;
            }
        }

        if (!$request->hasSession()) {
            return;
        }

        try {
            $this->companyService->syncSlaveFromSession();
        } catch (\Throwable) {
            // Contesto tenant non determinabile: si prosegue con lo stato di default;
            // il flusso di login/redirect gestirà l'assenza di contesto.
        }
    }
}
