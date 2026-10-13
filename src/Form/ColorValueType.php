<?php

namespace App\Form;

use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Campi delle etichette colorate (ColorValueTrait): valore + colore. È il genitore di
 * PracticeMarkType e PracticeTagType, che dichiarano entity e gruppo di validazione.
 */
class ColorValueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('value', CustomTextType::class)
            // Il formato #rrggbb lo controlla il validator (Regex).
            ->add('color', ColorType::class, [
                'empty_data' => '',
                'attr' => ['class' => 'form-control form-control-color w-100'],
            ]);
    }
}
