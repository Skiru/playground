<?php

declare(strict_types=1);

namespace App\Administration\UI\Form\Type;

use App\Administration\UI\Form\ExternalReferenceFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ExternalReferenceFormData> */
final class ExternalReferenceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('provider', TextType::class, ['label' => 'Dostawca'])
            ->add('externalId', TextType::class, ['label' => 'Identyfikator zewnętrzny'])
            ->add('sourceUrl', UrlType::class, ['label' => 'URL źródła', 'required' => false, 'empty_data' => null, 'default_protocol' => 'https']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ExternalReferenceFormData::class]);
    }
}
