<?php

namespace App\Form\Custom;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Testo lungo facoltativo. */
class CustomTextareaType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'empty_data' => null,
            'attr' => ['class' => 'form-control', 'rows' => 3],
        ]);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }
}
