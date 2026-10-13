<?php

namespace App\Form;

use App\Entity\Slave\User;
use App\Form\Custom\CustomChoiceType;
use App\Form\Custom\CustomEmailType;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** 10.2 Membro dello staff dall'area agenzia: stesso tipo per nuovo e modifica. I permessi sono a parte (10.1.5). */
class AgencyStaffType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', CustomTextType::class)
            ->add('surname', CustomTextType::class)
            ->add('email', CustomEmailType::class)
            ->add('staffRole', CustomChoiceType::class, [
                'choices' => array_flip(User::STAFF_ROLES),
                'invalid_message' => 'Ruolo non valido',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'validation_groups' => ['agency_staff'],
        ]);
    }
}
