<?php

namespace App\Form;

use App\Entity\Company;
use App\Entity\DemoRequest;
use App\Form\Custom\CustomEmailType;
use App\Form\Custom\CustomTextNullableType;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Richiesta demo dal form pubblico della homepage ("Attiva la demo").
 * L'e-mail si scrive due volte (RepeatedType): sull'entity arriva una sola e-mail, e se le due
 * non coincidono il form segna il campo senza toccare l'entity.
 */
class DemoRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('accountType', ChoiceType::class, [
                'choices' => ['Aziendale' => Company::TYPE_COMPANY, 'Professionista' => Company::TYPE_PROFESSIONAL],
                'expanded' => true,
                'invalid_message' => 'Seleziona il tipo di account',
            ])
            ->add('email', RepeatedType::class, [
                'type' => CustomEmailType::class,
                'invalid_message' => 'Le due e-mail non coincidono',
            ])
            ->add('businessName', CustomTextType::class)
            ->add('address', CustomTextType::class)
            ->add('civic', CustomTextType::class)
            ->add('city', CustomTextType::class)
            ->add('zip', CustomTextType::class, ['attr' => ['class' => 'form-control', 'maxlength' => 5]])
            ->add('sdi', CustomTextNullableType::class, ['attr' => ['class' => 'form-control', 'maxlength' => 7]])
            ->add('pec', CustomTextNullableType::class);

        // setAccountType() non accetta null: senza scelta arriva '' e lo ferma il NotBlank del validator.
        $builder->get('accountType')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value) => $value,
            static fn (?string $value) => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DemoRequest::class,
            'validation_groups' => ['demo_request'],
            'csrf_message' => 'Sessione scaduta, riprova a inviare il modulo.',
        ]);
    }
}
