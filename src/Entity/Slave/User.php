<?php

namespace App\Entity\Slave;

use App\Entity\Trait\ActiveTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Entity\Trait\UserSecurityTrait;
use App\Repository\Slave\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Utente del DB agenzia (slave): ROLE_AGENCY. È anche il "membro dello staff" del punto 10.
 * Creato dai ROLE_ADMIN dal pannello o dall'agenzia stessa; vive nel database della propria Company.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'eb_s_user')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use IdTrait;
    use UserSecurityTrait;
    use ActiveTrait;
    use TimestampsTrait;

    // 10.2.4 Ruolo del membro dello staff.
    public const STAFF_ADMIN = 'admin';
    public const STAFF_INTERNAL = 'internal';
    public const STAFF_EXTERNAL = 'external';

    /** @var array<string, string> */
    public const STAFF_ROLES = [
        self::STAFF_ADMIN => 'Amministratore',
        self::STAFF_INTERNAL => 'Interno',
        self::STAFF_EXTERNAL => 'Esterno',
    ];

    /**
     * 10.2.4 Ruolo: admin / interno / esterno. La colonna resta `is_admin` per non
     * perdere il dato storico: 'admin' ⇄ true, gli altri due ⇄ false, con il valore
     * preciso in `staff_role`.
     */
    #[ORM\Column(name: 'is_admin', type: 'boolean', options: ['default' => false])]
    private bool $admin = false;

    #[ORM\Column(name: 'staff_role', type: 'string', length: 16, options: ['default' => self::STAFF_INTERNAL])]
    private string $staffRole = self::STAFF_INTERNAL;

    /** @var Collection<int, UserPermission> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserPermission::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $userPermissions;

    public function __construct()
    {
        $this->role = 'ROLE_AGENCY';
        $this->userPermissions = new ArrayCollection();
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /** Tenuto per l'area admin, che ragiona ancora in termini di "amministratore sì/no". */
    public function setAdmin(bool $admin): static
    {
        $this->admin = $admin;
        $this->staffRole = $admin ? self::STAFF_ADMIN : self::STAFF_INTERNAL;

        return $this;
    }

    public function getStaffRole(): string
    {
        return $this->staffRole;
    }

    public function setStaffRole(string $staffRole): static
    {
        if (!isset(self::STAFF_ROLES[$staffRole])) {
            throw new \InvalidArgumentException('Ruolo staff non valido: ' . $staffRole);
        }

        $this->staffRole = $staffRole;
        $this->admin = $staffRole === self::STAFF_ADMIN;

        return $this;
    }

    public function getStaffRoleLabel(): string
    {
        return self::STAFF_ROLES[$this->staffRole] ?? $this->staffRole;
    }

    /** @return Collection<int, UserPermission> */
    public function getUserPermissions(): Collection
    {
        return $this->userPermissions;
    }

    /**
     * 10.1.5 Livello sulla sezione indicata: '' (nessuno), 'r' o 'rw'.
     * L'amministratore dell'agenzia scavalca la matrice e ha sempre 'rw'.
     */
    public function getPermissionType(string $slug): string
    {
        if ($this->isAdmin()) {
            return UserPermission::READ_WRITE;
        }

        foreach ($this->userPermissions as $up) {
            if ($up->getPermission()?->getSlug() === $slug) {
                return $up->getValue();
            }
        }

        return '';
    }

    public function canView(string $slug): bool
    {
        return in_array($this->getPermissionType($slug), [UserPermission::READ, UserPermission::READ_WRITE], true);
    }

    public function canEdit(string $slug): bool
    {
        return $this->getPermissionType($slug) === UserPermission::READ_WRITE;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
