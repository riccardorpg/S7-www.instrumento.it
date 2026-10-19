<?php

namespace App\Form;

use App\Entity\Slave\PracticeAlert;
use App\Form\Custom\CustomDateType;
use App\Form\Custom\CustomTextareaType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** 12.3.4.2 Avviso della pratica: stesso tipo per inserimento e modifica. */
class PracticeAlertType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Il setter dell'entity non accetta null: un campo svuotato non si scrive e, se
            // l'avviso ha già una data (modifica), si segnala qui; sul nuovo avviso la data
            // resta null e la intercetta il NotBlank del validator.
            ->add('remindAt', CustomDateType::class, [
                'setter' => static function (PracticeAlert $alert, ?\DateTimeImmutable $date, FormInterface $form): void {
                    if ($date !== null) {
                        $alert->setRemindAt($date);
                    } elseif ($alert->getRemindAt() !== null) {
                        $form->addError(new FormError('Campo obbligatorio'));
                    }
                },
            ])
            // Il messaggio è obbligatorio e il setter vuole una stringa: vuoto = ''.
            ->add('message', CustomTextareaType::class, ['empty_data' => '']);

        // Visibilità verso la controparte: l'agenzia sceglie se mostrarlo al notaio, il notaio
        // (for_notary) se mostrarlo all'agenzia.
        $builder->add($options['for_notary'] ? 'visibleToAgency' : 'visibleToNotary', CheckboxType::class, [
            'required' => false,
            'attr' => ['class' => 'form-check-input', 'role' => 'switch'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PracticeAlert::class,
            'validation_groups' => ['practice_alert'],
            'for_notary' => false,
        ]);
        $resolver->setAllowedTypes('for_notary', 'bool');
    }
}
