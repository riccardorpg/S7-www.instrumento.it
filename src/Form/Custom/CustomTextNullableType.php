<?php

namespace App\Form\Custom;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Testo facoltativo: vuoto diventa null, così a database non restano stringhe vuote. */
class CustomTextNullableType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'empty_data' => null,
            'attr' => ['class' => 'form-control'],
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
