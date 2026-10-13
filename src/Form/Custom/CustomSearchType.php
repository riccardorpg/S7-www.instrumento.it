<?php

namespace App\Form\Custom;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Campo di ricerca (autocomplete): non mappato, senza autocompletamento del browser. */
class CustomSearchType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'mapped' => false,
            'attr' => ['class' => 'form-control', 'autocomplete' => 'off'],
        ]);
    }

    public function getParent(): string
    {
        return SearchType::class;
    }
}
