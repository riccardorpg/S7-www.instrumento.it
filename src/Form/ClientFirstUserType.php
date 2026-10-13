<?php

namespace App\Form;

use App\Form\Custom\CustomTextType;
use App\Form\Model\ClientFirstUser;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Primo utente dell'agenzia nel nuovo cliente da richiesta demo. I campi stanno nello stesso
 * <form> HTML del nuovo cliente (CompanyNewType), ma è un form a sé con il suo nome e il suo token.
 */
class ClientFirstUserType extends AbstractType
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
            'data_class' => ClientFirstUser::class,
            'validation_groups' => ['client_first_user'],
        ]);
    }
}
