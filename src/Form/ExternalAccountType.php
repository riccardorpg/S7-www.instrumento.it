<?php

namespace App\Form;

use App\Entity\Master\Company;
use App\Entity\Master\ExternalAccount;
use App\Form\Custom\CustomEmailType;
use App\Form\Custom\CustomEntityHiddenType;
use App\Form\Custom\CustomSearchType;
use App\Form\Custom\CustomTextNullableType;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** 8. Account esterno: stesso tipo per inserimento e modifica (i campi coincidono). */
class ExternalAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', CustomTextType::class)
            ->add('surname', CustomTextType::class)
            ->add('email', CustomEmailType::class)
            ->add('phone', CustomTextNullableType::class)
            // Studio scelto dall'autocomplete: l'id nell'hidden, l'etichetta nel campo di ricerca
            // (non mappata, serve solo a ripresentarla se il form torna indietro con errori).
            ->add('company', CustomEntityHiddenType::class, ['class' => Company::class])
            ->add('companyLabel', CustomSearchType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExternalAccount::class,
            'validation_groups' => ['external_account'],
        ]);
    }
}
