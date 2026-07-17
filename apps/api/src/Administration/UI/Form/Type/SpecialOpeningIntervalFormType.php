<?php

declare(strict_types=1);

namespace App\Administration\UI\Form\Type;

use App\Administration\UI\Form\SpecialOpeningIntervalFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SpecialOpeningIntervalFormData> */
final class SpecialOpeningIntervalFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('opensAt', TextType::class, ['label' => 'Otwarcie', 'attr' => ['type' => 'time']])
            ->add('closesAt', TextType::class, ['label' => 'Zamknięcie', 'attr' => ['type' => 'time']])
            ->add('closesNextDay', CheckboxType::class, ['label' => 'Zamyka się następnego dnia', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SpecialOpeningIntervalFormData::class]);
    }
}
