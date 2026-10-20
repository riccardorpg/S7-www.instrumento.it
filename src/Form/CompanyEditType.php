<?php

namespace App\Form;

use App\Entity\Company;
use App\Form\Custom\CustomChoiceType;
use App\Form\Custom\CustomTextNullableType;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 7.1.9.1 Dati del cliente nella scheda dell'amministratore (modale "Modifica dati fiscali"):
 * anagrafica e dati fiscali, senza codice e database, che non si cambiano più.
 * Città e CAP vengono dal picker geografico, fuori dal form (li risolve il controller).
 * Il nuovo cliente (CompanyNewType) parte da qui e aggiunge codice e database.
 */
class CompanyEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('clientType', CustomChoiceType::class, [
                'choices' => ['Azienda' => Company::TYPE_COMPANY, 'Privato' => Company::TYPE_PROFESSIONAL],
                'invalid_message' => 'Tipo non valido',
            ])
            ->add('name', CustomTextType::class)
            ->add('vatNumber', CustomTextNullableType::class)
            ->add('taxCode', CustomTextNullableType::class)
            ->add('pec', CustomTextNullableType::class)
            ->add('sdi', CustomTextNullableType::class)
            ->add('address', CustomTextNullableType::class)
            ->add('civic', CustomTextNullableType::class);

        // setClientType() non accetta null: il vuoto arriva come '' e lo ferma il NotBlank del validator.
        $builder->get('clientType')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value) => $value,
            static fn (?string $value) => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
            'validation_groups' => ['company_edit'],
        ]);
    }
}
