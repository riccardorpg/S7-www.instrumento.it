<?php

namespace App\Command;

use App\Entity\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 10.1.5 Allinea il catalogo permessi (uno solo, comune a tutte le agenzie).
 * Da lanciare dopo aver aggiunto/rinominato una voce in {@see self::CATALOG}.
 * Idempotente: aggiorna etichetta e ordine delle voci esistenti (match sullo slug),
 * inserisce quelle nuove e rimuove quelle non più in catalogo (con i livelli collegati).
 */
#[AsCommand(name: 'app:sync-permissions', description: 'Allinea il catalogo permessi dello staff delle agenzie')]
class SyncPermissionsCommand extends Command
{
    /**
     * Sezioni della piattaforma su cui si assegnano i livelli.
     * Gli slug sono quelli usati in is_granted('view'|'edit', '<slug>').
     *
     * @var array<string, array{label: string, priority: int}>
     */
    public const CATALOG = [
        'practices' => ['label' => 'Pratiche', 'priority' => 10],
        'customers' => ['label' => 'Clienti', 'priority' => 20],
        'staff' => ['label' => 'Staff', 'priority' => 30],
        'configurations' => ['label' => 'Configurazioni', 'priority' => 40],
        'company' => ['label' => 'Anagrafica agenzia', 'priority' => 50],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $existing = [];
        foreach ($this->em->getRepository(Permission::class)->findAll() as $permission) {
            $existing[$permission->getSlug()] = $permission;
        }

        $added = $updated = $removed = 0;
        foreach (self::CATALOG as $slug => $meta) {
            $permission = $existing[$slug] ?? null;
            if ($permission === null) {
                $permission = (new Permission())->setSlug($slug);
                $this->em->persist($permission);
                ++$added;
            } else {
                unset($existing[$slug]);
                ++$updated;
            }
            $permission->setValue($meta['label'])->setPriority($meta['priority']);
        }

        // Voci non più in catalogo: via, insieme ai livelli assegnati (orphanRemoval).
        foreach ($existing as $obsolete) {
            $this->em->remove($obsolete);
            ++$removed;
        }

        $this->em->flush();
        $io->success(sprintf('Catalogo permessi allineato: %d nuove, %d aggiornate, %d rimosse.', $added, $updated, $removed));

        return Command::SUCCESS;
    }
}
