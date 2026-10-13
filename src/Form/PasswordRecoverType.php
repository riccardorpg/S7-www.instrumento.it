<?php

namespace App\Form;

use App\Form\Custom\CustomEmailType;
use App\Form\Model\PasswordRecover;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 3.3 Recupero password: pagina dedicata e modale delle pagine di accesso.
 * Il codice agenzia viaggia nascosto (vuoto per amministratori e notai).
 */
class PasswordRecoverType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', CustomEmailType::class, [
                'attr' => ['class' => 'form-control', 'autocomplete' => 'email', 'placeholder' => 'nome@esempio.it'],
            ])
            ->add('code', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PasswordRecover::class,
            'validation_groups' => ['password_recover'],
            'csrf_message' => 'Sessione scaduta, riprova.',
        ]);
    }
}
