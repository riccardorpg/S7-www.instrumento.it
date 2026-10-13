<?php

namespace App\Form;

use App\Form\Custom\CustomEmailType;
use App\Form\Custom\CustomTextareaType;
use App\Form\Custom\CustomTextType;
use App\Form\Model\Contact;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** 14.1 Modulo "Richiedi informazioni" della homepage (Model Contact). */
class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', CustomTextType::class)
            ->add('surname', CustomTextType::class)
            ->add('email', CustomEmailType::class)
            ->add('message', CustomTextareaType::class, ['attr' => ['class' => 'form-control', 'rows' => 4]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
            'validation_groups' => ['contact'],
            'csrf_message' => 'Sessione scaduta, riprova a inviare il modulo.',
        ]);
    }
}
