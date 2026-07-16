<?php

declare(strict_types=1);

namespace App\Administration\UI\Form\Type;

use App\Administration\UI\Form\AgeZoneFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<AgeZoneFormData> */
final class AgeZoneFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nazwa strefy'])
            ->add('minAgeMonths', IntegerType::class, ['label' => 'Wiek od (miesiące)', 'attr' => ['min' => 0, 'max' => 216]])
            ->add('maxAgeMonths', IntegerType::class, ['label' => 'Wiek do (miesiące)', 'required' => false, 'attr' => ['min' => 0, 'max' => 216]])
            ->add('notes', TextareaType::class, ['label' => 'Notatka', 'required' => false, 'empty_data' => null]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AgeZoneFormData::class]);
    }
}
