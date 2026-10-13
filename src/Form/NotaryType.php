<?php

namespace App\Form;

use App\Entity\Master\User;
use App\Form\Custom\CustomEmailType;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Notaio (utente master ROLE_NOTARY): stesso tipo per inserimento e modifica (i campi coincidono). */
class NotaryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', CustomTextType::class)
            ->add('surname', CustomTextType::class)
            ->add('email', CustomEmailType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'validation_groups' => ['user_notary'],
        ]);
    }
}
