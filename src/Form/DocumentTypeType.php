<?php

namespace App\Form;

use App\Entity\Slave\DocumentType;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 13.1 Tipo di documento: stesso tipo per nuovo e modifica. Fuori dal form la priorità
 * (drag & drop) e lo stato (attiva/disattiva dall'elenco).
 */
class DocumentTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('value', CustomTextType::class, ['attr' => ['class' => 'form-control', 'placeholder' => 'Es. Visura catastale']])
            ->add('mortgage', CheckboxType::class, [
                'required' => false,
                'attr' => ['class' => 'form-check-input', 'role' => 'switch'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DocumentType::class,
            'validation_groups' => ['document_type'],
        ]);
    }
}
