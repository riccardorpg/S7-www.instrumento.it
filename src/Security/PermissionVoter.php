<?php

namespace App\Security;

use App\Entity\User as AgencyUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * 10.1.5 Permessi di sezione dello staff agenzia.
 *
 * Uso: is_granted('view', 'customers') / is_granted('edit', 'staff'), dove il soggetto
 * è lo slug di una {@see \App\Entity\Permission}. Vale solo per gli utenti del DB
 * agenzia: admin e notai (utenti master) non hanno matrice e non passano di qui.
 *
 * @extends Voter<string, string>
 */
class PermissionVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT], true) && is_string($subject) && $subject !== '';
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $vote ??= new Vote();

        $user = $token->getUser();
        if (!$user instanceof AgencyUser) {
            $vote->addReason('Permessi di sezione: valgono solo per gli utenti agenzia.');

            return false;
        }

        $granted = match ($attribute) {
            self::VIEW => $user->canView($subject),
            self::EDIT => $user->canEdit($subject),
            default => false,
        };

        if (!$granted) {
            $vote->addReason(sprintf(
                'Livello "%s" sulla sezione "%s": insufficiente per "%s".',
                $user->getPermissionType($subject) ?: 'nessuno',
                $subject,
                $attribute
            ));
        }

        return $granted;
    }
}
