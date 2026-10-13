<?php

namespace App\Form;

use App\Form\Model\CompanyLogo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 7.1.9.8 Logo dell'agenzia: le regole sul file (tipo e dimensione) stanno nel validator
 * del Model, il salvataggio su disco in CompanyLogoService.
 */
class CompanyLogoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'required' => false,
            'attr' => ['class' => 'form-control', 'accept' => 'image/jpeg,image/png,image/webp'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompanyLogo::class,
            'validation_groups' => ['company_logo'],
        ]);
    }
}
