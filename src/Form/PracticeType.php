<?php

namespace App\Form;

use App\Entity\Customer;
use App\Entity\Practice;
use App\Entity\PracticeMark;
use App\Entity\PracticeTag;
use App\Form\Custom\CustomDateType;
use App\Form\Custom\CustomEntityHiddenType;
use App\Form\Custom\CustomSearchType;
use App\Form\Custom\CustomTextareaType;
use App\Form\Custom\CustomTextType;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 12.2 Pratica: stesso tipo per inserimento (pagina dedicata) e modifica (modale della scheda),
 * i campi coincidono. Città e CAP non sono qui: li invia il picker condiviso (city_id / zip_id)
 * e li risolve il controller dopo l'invio.
 */
class PracticeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // 12.2.1 / 12.2.2 Parti scelte dall'autocomplete: l'id nell'hidden, l'etichetta nel campo
            // di ricerca (non mappata, serve a ripresentarla se il form torna indietro con errori).
            ->add('seller', CustomEntityHiddenType::class, ['class' => Customer::class])
            ->add('sellerLabel', CustomSearchType::class)
            ->add('buyer', CustomEntityHiddenType::class, ['class' => Customer::class])
            ->add('buyerLabel', CustomSearchType::class)
            // 12.2.3 Il setter dell'entity non accetta null: un campo svuotato non si scrive e,
            // se la pratica ha già una data (modifica), si segnala qui; sulla nuova pratica la data
            // resta null e la intercetta il NotBlank del validator.
            ->add('createdAt', CustomDateType::class, [
                'setter' => static function (Practice $practice, ?\DateTimeImmutable $date, FormInterface $form): void {
                    if ($date !== null) {
                        $practice->setCreatedAt($date);
                    } elseif ($practice->getCreatedAt() !== null) {
                        $form->addError(new FormError('Campo obbligatorio'));
                    }
                },
            ])
            // 12.2.7 Contrassegno (uno solo)
            ->add('mark', EntityType::class, [
                'class' => PracticeMark::class,
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('m')->orderBy('m.value', 'ASC'),
                'choice_label' => 'value',
                'placeholder' => 'Nessuno',
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            // 12.2.4 Con / senza mutuo
            ->add('mortgage', CheckboxType::class, [
                'required' => false,
                'attr' => ['class' => 'form-check-input', 'role' => 'switch'],
            ])
            ->add('address', CustomTextType::class, ['attr' => ['class' => 'form-control', 'maxlength' => 255, 'placeholder' => 'Via e civico']])
            // 12.2.9 Notaio: l'autocomplete scrive l'e-mail nell'hidden, l'etichetta col nome è di
            // sola presentazione. Il controller risolve l'e-mail nel notaio (e controlla che esista).
            ->add('notaryEmail', HiddenType::class, ['mapped' => false, 'required' => false, 'error_bubbling' => false])
            ->add('notaryLabel', CustomSearchType::class)
            ->add('subject', CustomTextareaType::class, ['attr' => ['class' => 'form-control', 'rows' => 4]])
            // 12.2.6 Tag (più di uno). L'entity ha solo addTag()/clearTags(): la scrittura passa da qui.
            ->add('tags', EntityType::class, [
                'class' => PracticeTag::class,
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('t')->orderBy('t.value', 'ASC'),
                'choice_label' => 'value',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'setter' => static function (Practice $practice, iterable $tags): void {
                    $selected = $tags instanceof Collection ? $tags->toArray() : [...$tags];
                    $practice->clearTags();
                    foreach ($selected as $tag) {
                        $practice->addTag($tag);
                    }
                },
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Practice::class,
            'validation_groups' => ['practice'],
        ]);
    }
}
