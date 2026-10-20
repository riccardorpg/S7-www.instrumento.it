<?php

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Un solo accesso per tutti: e-mail e password (l'e-mail è unica su tutta la piattaforma).
 * Il ruolo dell'utente decide l'area di arrivo; licenza e stato li controlla {@see UserChecker}.
 */
class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    public const LOGIN_ROUTE = 'login';
    public const LOGIN_CHECK_ROUTE = 'login_check';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Scatta sul POST verso la check path (login_check), non su getLoginUrl():
     * usiamo custom_authenticator senza il blocco form_login.
     */
    public function supports(Request $request): bool
    {
        // Confronto con base URL inclusa (getBaseUrl + pathInfo) così funziona anche quando
        // l'app è servita in sottocartella, esattamente come fa AbstractLoginFormAuthenticator.
        return $request->isMethod('POST')
            && $request->getBaseUrl() . $request->getPathInfo() === $this->urlGenerator->generate(self::LOGIN_CHECK_ROUTE);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    public function authenticate(Request $request): Passport
    {
        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $password = (string) $request->request->get('password', '');
        $csrfToken = (string) $request->request->get('_csrf_token', '');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        $userBadge = new UserBadge($email, function (string $identifier) {
            return $this->users->findOneByEmail($identifier) ?? throw new UserNotFoundException();
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
            in_array('ROLE_USER', $roles, true)   => 'agency_index',
            default                               => self::LOGIN_ROUTE,
        };

        return new RedirectResponse($this->urlGenerator->generate($route));
    }
}
