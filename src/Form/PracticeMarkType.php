<?php

namespace App\Form;

use App\Entity\Slave\PracticeMark;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** 13.2 Contrassegno delle pratiche: valore + colore (campi in ColorValueType), stesso tipo per nuovo e modifica. */
class PracticeMarkType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PracticeMark::class,
            'validation_groups' => ['practice_mark'],
        ]);
    }

    public function getParent(): string
    {
        return ColorValueType::class;
    }
}
