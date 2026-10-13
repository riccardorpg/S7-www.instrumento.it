<?php

namespace App\Form;

use App\Entity\Master\LicenseType;
use App\Form\Custom\CustomIntegerType;
use App\Form\Model\LicenseNew;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Crea licenza: tipo e quantità (Model LicenseNew). */
class LicenseNewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EntityType::class, [
                'class' => LicenseType::class,
                'em' => 'master',
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('t')->orderBy('t.spaceIncluded', 'ASC')->addOrderBy('t.type', 'ASC'),
                'choice_label' => static fn (LicenseType $t) => $t->getLabel() . ' (' . number_format($t->getSpaceIncluded(), 0, ',', '.') . ' MB)',
                'invalid_message' => 'Tipo non valido',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('quantity', CustomIntegerType::class, [
                'attr' => ['class' => 'form-control', 'min' => 1, 'max' => 50],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LicenseNew::class,
            'validation_groups' => ['license_new'],
        ]);
    }
}
