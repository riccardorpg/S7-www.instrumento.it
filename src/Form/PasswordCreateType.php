<?php

namespace App\Form;

use App\Form\Model\PasswordCreate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** 4. Creazione della password: le regole di robustezza stanno in Model/PasswordCreate.yml. */
class PasswordCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('password', PasswordType::class, [
                'attr' => ['class' => 'form-control', 'autocomplete' => 'new-password', 'autofocus' => 'autofocus'],
            ])
            ->add('passwordConfirm', PasswordType::class, [
                'attr' => ['class' => 'form-control', 'autocomplete' => 'new-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PasswordCreate::class,
            'validation_groups' => ['password_create'],
            'csrf_message' => 'Sessione scaduta, riprova.',
        ]);
    }
}
