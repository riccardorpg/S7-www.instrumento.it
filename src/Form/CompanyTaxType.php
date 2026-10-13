<?php

namespace App\Form;

use App\Entity\Master\Company;
use App\Form\Custom\CustomTextNullableType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** 7.1.9.1 Dati fiscali dall'anagrafica dell'agenzia: P. IVA, codice fiscale, SDI e PEC. */
class CompanyTaxType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('vatNumber', CustomTextNullableType::class)
            ->add('taxCode', CustomTextNullableType::class, ['attr' => ['class' => 'form-control text-uppercase']])
            ->add('sdi', CustomTextNullableType::class, ['attr' => ['class' => 'form-control text-uppercase']])
            ->add('pec', CustomTextNullableType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
            'validation_groups' => ['company_tax'],
        ]);
    }
}
