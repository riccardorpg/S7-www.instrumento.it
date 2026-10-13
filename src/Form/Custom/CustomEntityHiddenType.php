<?php

namespace App\Form\Custom;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Id di un'entity scelta con un autocomplete (campo testo + tendina JS): nel form c'è solo
 * l'hidden con l'id, qui diventa l'entity. Funziona anche con le entity Slave, perché il
 * manager si ricava dalla classe (la connessione è già puntata sull'agenzia corrente).
 * Opzione obbligatoria: class.
 */
class CustomEntityHiddenType extends AbstractType
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $class = $options['class'];
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?object $entity) => $entity !== null && method_exists($entity, 'getId') ? $entity->getId() : null,
            function (mixed $id) use ($class): ?object {
                if ($id === null || $id === '' || (int) $id === 0) {
                    return null;
                }
                $entity = $this->registry->getManagerForClass($class)?->find($class, (int) $id);
                if ($entity === null) {
                    throw new TransformationFailedException(sprintf('Elemento %s non trovato.', $id));
                }

                return $entity;
            }
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('class');
        $resolver->setAllowedTypes('class', 'string');
        $resolver->setDefaults([
            'required' => false,
            // Gli hidden di Symfony "risalgono" l'errore al form: qui deve restare sul campo,
            // così il contenitore dell'autocomplete si colora come gli altri.
            'error_bubbling' => false,
            'invalid_message' => 'Elemento non valido: sceglilo dall\'elenco',
        ]);
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }
}
