<?php

namespace App\Form\Custom;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** E-mail: il formato lo controlla il validator (Email), qui solo il widget. */
class CustomEmailType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'empty_data' => '',
            'attr' => ['class' => 'form-control', 'autocomplete' => 'off'],
        ]);
    }

    public function getParent(): string
    {
        return EmailType::class;
    }
}
