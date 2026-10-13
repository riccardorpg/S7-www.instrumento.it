<?php

namespace App\Form\Custom;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Data obbligatoria nel formato del datepicker (gg-mm-aaaa). */
class CustomDateType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'widget' => 'single_text',
            'format' => 'dd-MM-yyyy',
            'html5' => false,
            'input' => 'datetime_immutable',
            'invalid_message' => 'Inserire una data valida',
            'attr' => ['class' => 'form-control datepicker', 'autocomplete' => 'off', 'placeholder' => 'gg-mm-aaaa'],
        ]);
    }

    public function getParent(): string
    {
        return DateType::class;
    }
}
