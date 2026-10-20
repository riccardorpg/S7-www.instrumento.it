<?php

namespace App\Form;

use App\Entity\User;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Modifica del collaboratore dalla sua scheda nello staff del notaio: solo nome e cognome
 * (l'e-mail è l'account, può essere nello staff di altri notai).
 */
class NotaryStaffEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', CustomTextType::class)
            ->add('surname', CustomTextType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'validation_groups' => ['notary_staff_edit'],
        ]);
    }
}
