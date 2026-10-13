<?php

namespace App\Form;

use App\Entity\Slave\PracticeTag;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** 13.3 Tag delle pratiche: valore + colore (campi in ColorValueType), stesso tipo per nuovo e modifica. */
class PracticeTagType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PracticeTag::class,
            'validation_groups' => ['practice_tag'],
        ]);
    }

    public function getParent(): string
    {
        return ColorValueType::class;
    }
}
