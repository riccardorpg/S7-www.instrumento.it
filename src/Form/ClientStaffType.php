<?php

namespace App\Form;

use App\Entity\User;
use App\Form\Custom\CustomEmailType;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 7.1.9.6 Nuovo utente staff dalla scheda cliente dell'amministratore. L'unicità dell'e-mail
 * si controlla sul database dell'agenzia: il controller punta lo slave prima di gestire il form.
 */
class ClientStaffType extends AbstractType
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
            'validation_groups' => ['client_staff'],
        ]);
    }
}
