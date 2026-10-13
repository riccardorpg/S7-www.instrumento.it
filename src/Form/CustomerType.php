<?php

namespace App\Form;

use App\Entity\Slave\Customer;
use App\Form\Custom\CustomDateNullableType;
use App\Form\Custom\CustomEmailType;
use App\Form\Custom\CustomTextareaType;
use App\Form\Custom\CustomTextNullableType;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 11.2.1 / 11.2.2 Cliente dell'agenzia: dati anagrafici e fiscali. Stesso tipo per il nuovo
 * cliente (elenco), la modifica (scheda) e il nuovo cliente al volo dalla pratica (JSON).
 */
class CustomerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Il nome è facoltativo: per le società basta la ragione sociale nel cognome.
            ->add('name', CustomTextType::class, ['required' => false])
            ->add('surname', CustomTextType::class)
            ->add('birthPlace', CustomTextNullableType::class)
            // data-date-end-date: il calendario non propone date future (lo legge bootstrap-datepicker).
            ->add('birthDate', CustomDateNullableType::class, ['attr' => [
                'class' => 'form-control datepicker',
                'autocomplete' => 'off',
                'placeholder' => 'gg-mm-aaaa',
                'data-date-end-date' => '0d',
            ]])
            ->add('address', CustomTextNullableType::class)
            ->add('city', CustomTextNullableType::class)
            ->add('zip', CustomTextNullableType::class)
            ->add('email', CustomEmailType::class, ['required' => false, 'empty_data' => null])
            ->add('phone', CustomTextNullableType::class)
            ->add('fiscalCode', CustomTextNullableType::class, ['attr' => ['class' => 'form-control text-uppercase']])
            ->add('vatNumber', CustomTextNullableType::class)
            ->add('pec', CustomEmailType::class, ['required' => false, 'empty_data' => null])
            ->add('sdi', CustomTextNullableType::class, ['attr' => ['class' => 'form-control text-uppercase']])
            ->add('notes', CustomTextareaType::class, ['attr' => ['class' => 'form-control', 'rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Customer::class,
            'validation_groups' => ['customer'],
        ]);
    }
}
