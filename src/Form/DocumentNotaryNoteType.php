<?php

namespace App\Form;

use App\Entity\Slave\Document;
use App\Form\Custom\CustomTextareaType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** 17.1.1.1.5.2.6 Note del notaio su un allegato (vuoto = nessuna nota). */
class DocumentNotaryNoteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('notaryNote', CustomTextareaType::class, ['attr' => ['class' => 'form-control', 'rows' => 4]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'validation_groups' => ['document_notary_note'],
        ]);
    }
}
