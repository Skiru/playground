<?php

declare(strict_types=1);

namespace App\Administration\UI\Form\Type;

use App\Administration\UI\Form\PlaceCoreFormData;
use App\Places\Application\Command\VerificationStatusInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<PlaceCoreFormData> */
final class PlaceCoreFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nazwa'])
            ->add('slug', TextType::class, ['label' => 'Slug'])
            ->add('shortDescription', TextareaType::class, ['label' => 'Krótki opis'])
            ->add('description', TextareaType::class, ['label' => 'Opis'])
            ->add('addressLine1', TextType::class, ['label' => 'Adres'])
            ->add('addressLine2', TextType::class, ['label' => 'Adres, ciąg dalszy', 'required' => false, 'empty_data' => null])
            ->add('postalCode', TextType::class, ['label' => 'Kod pocztowy'])
            ->add('citySlug', ChoiceType::class, ['label' => 'Miasto', 'choices' => $options['city_choices']])
            ->add('countryCode', TextType::class, ['label' => 'Kod kraju'])
            ->add('latitude', NumberType::class, ['label' => 'Szerokość geograficzna', 'scale' => 7, 'html5' => true])
            ->add('longitude', NumberType::class, ['label' => 'Długość geograficzna', 'scale' => 7, 'html5' => true])
            ->add('timezone', TextType::class, ['label' => 'Strefa czasowa'])
            ->add('indoor', CheckboxType::class, ['label' => 'Wewnątrz', 'required' => false])
            ->add('outdoor', CheckboxType::class, ['label' => 'Na zewnątrz', 'required' => false])
            ->add('freeEntry', CheckboxType::class, ['label' => 'Bezpłatne', 'required' => false])
            ->add('priceDescription', TextType::class, ['label' => 'Opis ceny', 'required' => false, 'empty_data' => null])
            ->add('websiteUrl', UrlType::class, ['label' => 'Strona WWW', 'required' => false, 'empty_data' => null, 'default_protocol' => 'https'])
            ->add('phone', TelType::class, ['label' => 'Telefon', 'required' => false, 'empty_data' => null])
            ->add('verificationStatus', EnumType::class, ['label' => 'Status weryfikacji', 'class' => VerificationStatusInput::class]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PlaceCoreFormData::class]);
        $resolver->setRequired('city_choices');
        $resolver->setAllowedTypes('city_choices', 'array');
    }
}
