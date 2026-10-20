<?php

namespace App\Form;

use App\Entity\LicenseType;
use App\Form\Custom\CustomIntegerType;
use App\Form\Model\LicenseFreeDelete;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Elimina licenze libere: tipo (lo imposta il bottone della riga) e quantità (Model LicenseFreeDelete). */
class LicenseFreeDeleteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EntityType::class, [
                'class' => LicenseType::class,
                'choice_label' => 'label',
                'invalid_message' => 'Tipo non valido',
            ])
            ->add('quantity', CustomIntegerType::class, [
                'attr' => ['class' => 'form-control', 'min' => 1],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LicenseFreeDelete::class,
            'validation_groups' => ['license_free_delete'],
        ]);
    }
}
