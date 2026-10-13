<?php

namespace App\Form;

use App\Entity\Master\Company;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 7.1.10 Nuovo cliente dell'amministratore: i campi della modifica (CompanyEditType) più
 * codice cliente e nome del database dedicato, che si scelgono solo alla creazione.
 */
class CompanyNewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', CustomTextType::class, [
                'attr' => ['class' => 'form-control text-uppercase', 'placeholder' => 'es. AG003'],
            ])
            ->add('dbName', CustomTextType::class, [
                'attr' => ['class' => 'form-control', 'placeholder' => 'es. instrumento_ag001'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
            'validation_groups' => ['company_new'],
        ]);
    }

    public function getParent(): string
    {
        return CompanyEditType::class;
    }
}
