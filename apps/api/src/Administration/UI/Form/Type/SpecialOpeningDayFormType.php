<?php

declare(strict_types=1);

namespace App\Administration\UI\Form\Type;

use App\Administration\UI\Form\SpecialOpeningDayFormData;
use App\Places\Application\Command\SpecialOpeningDayModeInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SpecialOpeningDayFormData> */
final class SpecialOpeningDayFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('localDate', TextType::class, ['label' => 'Data', 'attr' => ['type' => 'date']])
            ->add('mode', EnumType::class, ['label' => 'Tryb dnia', 'class' => SpecialOpeningDayModeInput::class])
            ->add('note', TextareaType::class, ['label' => 'Notatka', 'required' => false, 'empty_data' => null])
            ->add('intervals', CollectionType::class, ['label' => 'Przedziały niestandardowe', 'entry_type' => SpecialOpeningIntervalFormType::class, 'allow_add' => true, 'allow_delete' => true, 'by_reference' => false, 'prototype' => true, 'prototype_name' => '__special_interval__', 'error_bubbling' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SpecialOpeningDayFormData::class]);
    }
}
