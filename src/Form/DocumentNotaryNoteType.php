<?php

namespace App\Form;

use App\Entity\Document;
use App\Form\Custom\CustomTextareaType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 17.1.1.1.5.2.6 Note del notaio su un allegato (vuoto = nessuna nota), con la scelta di
 * rimandare il documento all'agenzia: "Da verificare agenzia" è lo stato della riga
 * documentale, non dell'allegato, quindi il campo non è mappato e lo applica il controller.
 */
class DocumentNotaryNoteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('notaryNote', CustomTextareaType::class, ['attr' => ['class' => 'form-control', 'rows' => 4]])
            ->add('toVerifyAgency', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-check-input', 'role' => 'switch'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'validation_groups' => ['document_notary_note'],
        ]);
    }
}
