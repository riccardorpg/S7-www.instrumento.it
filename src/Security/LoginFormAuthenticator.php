<?php

namespace App\Security;

use App\Entity\Master\User as MasterUser;
use App\Entity\Slave\User as SlaveUser;
use App\Service\CompanyService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Login in 2 step, come eposmanager: il codice identifica il DATABASE, poi email+password
 * risolvono l'utente DENTRO quel database. La stessa email può quindi esistere in più
 * agenzie: è il codice a disambiguare.
 *
 * Risoluzione dell'utente:
 *   - con `codice` (pagina dedicata agenzia) → si punta lo slave al DB della Company e si
 *     carica App\Entity\Slave\User per email da QUEL DB (nessuna ricerca su master);
 *   - con `staff` (accesso staff) → si carica App\Entity\Master\User per email dal master.
 *
 * Il redirect post-login dipende dal ruolo.
 */
class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    public const LOGIN_ROUTE = 'login';
    public const LOGIN_CHECK_ROUTE = 'login_check';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ManagerRegistry $registry,
        private readonly CompanyService $companyService,
    ) {
    }

    /**
     * Scatta sul POST verso la check path (login_check), non su getLoginUrl():
     * usiamo custom_authenticator senza il blocco form_login.
     */
    public function supports(Request $request): bool
    {
        // Confronto con base URL inclusa (getBaseUrl + pathInfo) così funziona anche quando
        // l'app è servita in sottocartella (es. localhost/www.instrumento.it/public/...),
        // esattamente come fa AbstractLoginFormAuthenticator internamente.
        return $request->isMethod('POST')
            && $request->getBaseUrl() . $request->getPathInfo() === $this->urlGenerator->generate(self::LOGIN_CHECK_ROUTE);
    }

    /**
     * URL della pagina di login: usato dall'entry point e come redirect su errore.
     * Se il form portava un codice agenzia (pagina dedicata) o il flag staff, si torna lì.
     */
    protected function getLoginUrl(Request $request): string
    {
        $code = $request->get('code');
        if (is_string($code) && $code !== '') {
            return $this->urlGenerator->generate('login_dedicated', ['code' => $code]);
        }

        if ($request->get('staff')) {
            return $this->urlGenerator->generate('login_staff');
        }

        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    public function authenticate(Request $request): Passport
    {
        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $password = (string) $request->request->get('password', '');
        $csrfToken = (string) $request->request->get('_csrf_token', '');
        $code = trim((string) $request->request->get('code', ''));
        $isStaff = (bool) $request->request->get('staff', false);

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        $userBadge = new UserBadge($email, function (string $identifier) use ($code, $isStaff) {
            // Accesso STAFF (ADMIN / NOTARY): solo master, nessun tenant.
            if ($isStaff || $code === '') {
                $this->companyService->clearSession();
                $masterUser = $this->registry->getManager('master')
                    ->getRepository(MasterUser::class)
                    ->findOneBy(['email' => $identifier]);

                if ($masterUser instanceof MasterUser) {
                    return $masterUser;
                }

                throw new UserNotFoundException();
            }

            // Accesso AGENZIA: il codice identifica il DB. Punta lo slave e cerca SOLO lì.
            $company = $this->companyService->getCompanyByCode($code);
            if ($company !== null && $company->isActive()) {
                $this->companyService->switchToCompany($company);

                $agencyUser = $this->registry->getManager('slave')
                    ->getRepository(SlaveUser::class)
                    ->findOneBy(['email' => $identifier]);

                if ($agencyUser instanceof SlaveUser) {
                    // Come in eaml: senza una licenza valida (assegnata, attiva, non scaduta) non si entra.
                    if (!$company->hasValidLicense()) {
                        throw new CustomUserMessageAuthenticationException('La licenza della tua agenzia è scaduta o non attiva: contatta l\'amministratore.');
                    }

                    return $agencyUser;
                }
            }

            throw new UserNotFoundException();
        });

        return new Passport(
            $userBadge,
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
                new RememberMeBadge(),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $roles = $token->getRoleNames();

        $route = match (true) {
            in_array('ROLE_ADMIN', $roles, true)  => 'admin_index',
            in_array('ROLE_NOTARY', $roles, true) => 'notary_index',
            in_array('ROLE_AGENCY', $roles, true) => 'agency_index',
            default                               => self::LOGIN_ROUTE,
        };

        $response = new RedirectResponse($this->urlGenerator->generate($route));

        // Utente agenzia: ricorda il tenant per tutta la durata del "resta collegato".
        // Se la sessione scade prima del cookie, il listener di request ritrova da qui il
        // database su cui caricare l'utente (senza, la connessione slave resterebbe sul
        // master e il ripristino da remember-me andrebbe in errore).
        $code = trim((string) $request->request->get('code', ''));
        if ($code !== '' && in_array('ROLE_AGENCY', $roles, true)) {
            $response->headers->setCookie(Cookie::create(
                CompanyService::COOKIE_TENANT,
                $code,
                new \DateTimeImmutable('+7 days'),
                $request->getBasePath() ?: '/',
                null,
                $request->isSecure(),
                true,
                false,
                Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }
}
