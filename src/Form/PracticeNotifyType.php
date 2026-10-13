<?php

namespace App\Form;

use App\Form\Custom\CustomChoiceType;
use App\Form\Custom\CustomTextareaType;
use App\Form\Model\PracticeNotify;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 12.3.2.7 Notifica di aggiornamento file. Opzione obbligatoria recipients (e-mail => etichetta):
 * i destinatari possibili della pratica; un valore fuori elenco lo scarta la tendina stessa.
 */
class PracticeNotifyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['recipients'] as $email => $label) {
            $choices[$label . ' (' . $email . ')'] = $email;
        }

        $builder
            ->add('recipient', CustomChoiceType::class, [
                'choices' => $choices,
                'placeholder' => 'Seleziona…',
                'required' => false,
                'invalid_message' => 'Destinatario non valido per questa pratica',
            ])
            // Il messaggio è obbligatorio e il setter vuole una stringa: vuoto = ''.
            ->add('message', CustomTextareaType::class, ['empty_data' => '', 'attr' => ['class' => 'form-control', 'rows' => 4]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('recipients');
        $resolver->setAllowedTypes('recipients', 'array');
        $resolver->setDefaults([
            'data_class' => PracticeNotify::class,
            'validation_groups' => ['practice_notify'],
        ]);
    }
}
