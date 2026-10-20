<?php

namespace App\Entity;

use App\Entity\Trait\ActiveTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Entity\Trait\UserSecurityTrait;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Utente della piattaforma, uno per e-mail. Il ruolo dice l'area:
 *  - ROLE_ADMIN: amministrazione (creato con app:create-user);
 *  - ROLE_NOTARY: notaio, vede le pratiche che le agenzie gli assegnano;
 *  - ROLE_NOTARY_STAFF: collaboratore di uno o più notai, vede (e lavora come il notaio) le pratiche
 *    che i suoi notai gli assegnano;
 *  - ROLE_USER: staff di un'agenzia (10.), legato alla sua Company, con ruolo di staff
 *    e matrice dei permessi di sezione.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'eb_user')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use IdTrait;
    use UserSecurityTrait;
    use ActiveTrait;
    use TimestampsTrait;

    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_NOTARY = 'ROLE_NOTARY';
    public const ROLE_NOTARY_STAFF = 'ROLE_NOTARY_STAFF';
    public const ROLE_USER = 'ROLE_USER';

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

    /** Agenzia di appartenenza: solo per ROLE_USER, null per amministratori e notai. */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Company $company = null;

    /**
     * 10.2.4 Ruolo nello staff: admin / interno / esterno. `is_admin` resta allineato
     * ('admin' ⇄ true), il valore preciso sta in `staff_role`. Solo per ROLE_USER.
     */
    #[ORM\Column(name: 'is_admin', type: 'boolean', options: ['default' => false])]
    private bool $admin = false;

    #[ORM\Column(name: 'staff_role', type: 'string', length: 16, options: ['default' => self::STAFF_INTERNAL])]
    private string $staffRole = self::STAFF_INTERNAL;

    /** @var Collection<int, UserPermission> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserPermission::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $userPermissions;

    /**
     * Staff del notaio: i collaboratori (ROLE_NOTARY_STAFF) a cui può assegnare le sue pratiche.
     * Solo per ROLE_NOTARY. Un collaboratore può stare nello staff di più notai.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'notaries')]
    #[ORM\JoinTable(name: 'eb_notary_staff')]
    #[ORM\JoinColumn(name: 'notary_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'staff_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['surname' => 'ASC', 'name' => 'ASC'])]
    private Collection $collaborators;

    /**
     * Notai di cui il collaboratore fa parte dello staff. Solo per ROLE_NOTARY_STAFF.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'collaborators')]
    private Collection $notaries;

    public function __construct()
    {
        $this->userPermissions = new ArrayCollection();
        $this->collaborators = new ArrayCollection();
        $this->notaries = new ArrayCollection();
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isNotary(): bool
    {
        return $this->role === self::ROLE_NOTARY;
    }

    public function isAgencyUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function isNotaryStaff(): bool
    {
        return $this->role === self::ROLE_NOTARY_STAFF;
    }

    /** @return Collection<int, User> */
    public function getCollaborators(): Collection
    {
        return $this->collaborators;
    }

    public function addCollaborator(User $collaborator): static
    {
        if (!$this->collaborators->contains($collaborator)) {
            $this->collaborators->add($collaborator);
            $collaborator->notaries->add($this);
        }

        return $this;
    }

    public function removeCollaborator(User $collaborator): static
    {
        $this->collaborators->removeElement($collaborator);
        $collaborator->notaries->removeElement($this);

        return $this;
    }

    public function hasCollaborator(User $collaborator): bool
    {
        return $this->collaborators->contains($collaborator);
    }

    /** @return Collection<int, User> */
    public function getNotaries(): Collection
    {
        return $this->notaries;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    /** Amministratore dell'agenzia (staff): per gli amministratori della piattaforma vale isPlatformAdmin(). */
    public function isAdmin(): bool
    {
        return $this->isAgencyUser() ? $this->admin : $this->isPlatformAdmin();
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
     * L'amministratore dell'agenzia scavalca la matrice e ha sempre 'rw';
     * fuori dallo staff di un'agenzia la matrice non esiste.
     */
    public function getPermissionType(string $slug): string
    {
        if (!$this->isAgencyUser()) {
            return '';
        }

        if ($this->admin) {
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
