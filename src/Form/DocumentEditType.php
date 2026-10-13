<?php

namespace App\Form;

use App\Entity\Slave\Document;
use App\Form\Custom\CustomTextareaType;
use App\Form\Custom\CustomTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** 12.3.2.5.5 Modifica di un allegato dall'agenzia: nome e note dell'agente. */
class DocumentEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', CustomTextType::class)
            ->add('agentNote', CustomTextareaType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'validation_groups' => ['document_edit'],
        ]);
    }
}
