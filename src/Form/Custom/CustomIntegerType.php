<?php

namespace App\Form\Custom;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Numero intero. */
class CustomIntegerType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'invalid_message' => 'Inserire un numero intero',
            'attr' => ['class' => 'form-control'],
        ]);
    }

    public function getParent(): string
    {
        return IntegerType::class;
    }
}
