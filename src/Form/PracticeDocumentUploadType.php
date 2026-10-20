<?php

namespace App\Form;

use App\Entity\PracticeDocument;
use App\Form\Custom\CustomTextareaType;
use App\Form\Custom\CustomTextType;
use App\Form\Model\PracticeDocumentUpload;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 12.3.2.6 / 17.1.1.1.5.3 Nuovo allegato (agenzia e notaio): le regole sul file (solo PDF)
 * stanno nel validator del Model, il salvataggio su disco in DocumentStorageService.
 * Opzione obbligatoria rows: le righe documentali della pratica fra cui scegliere.
 */
class PracticeDocumentUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('practiceDocument', EntityType::class, [
                'class' => PracticeDocument::class,
                'choices' => $options['rows'],
                'choice_label' => 'label',
                'placeholder' => 'Seleziona…',
                'required' => false,
                'invalid_message' => 'Documento non valido',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('name', CustomTextType::class)
            ->add('file', FileType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control', 'accept' => 'application/pdf'],
            ])
            ->add('note', CustomTextareaType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('rows');
        $resolver->setAllowedTypes('rows', 'iterable');
        $resolver->setDefaults([
            'data_class' => PracticeDocumentUpload::class,
            'validation_groups' => ['practice_document_upload'],
        ]);
    }
}
