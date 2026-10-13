<?php

namespace App\Form\Custom;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Testo obbligatorio: vuoto diventa stringa vuota (il NotBlank del validator lo intercetta). */
class CustomTextType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'empty_data' => '',
            'attr' => ['class' => 'form-control'],
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
