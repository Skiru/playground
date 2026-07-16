<?php

declare(strict_types=1);

namespace App\Administration\UI\Form\Type;

use App\Administration\UI\Form\PlaceAdminFormData;
use App\Places\Application\Command\OpeningHoursModeInput;
use App\Places\Application\PlaceRepository;
use App\Places\Domain\Amenity;
use App\Places\Domain\Category;
use App\Places\Domain\City;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<PlaceAdminFormData> */
final class PlaceAdminFormType extends AbstractType
{
    public function __construct(private readonly PlaceRepository $places)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $categories = $this->places->allCategories();
        $amenities = $this->places->allAmenities();
        $builder
            ->add('core', PlaceCoreFormType::class, ['label' => false, 'city_choices' => self::choices($this->places->allCities())])
            ->add('categorySlugs', ChoiceType::class, ['label' => 'Kategorie', 'choices' => self::choices($categories), 'multiple' => true, 'expanded' => true])
            ->add('primaryCategorySlug', ChoiceType::class, ['label' => 'Kategoria główna', 'choices' => self::choices($categories)])
            ->add('amenitySlugs', ChoiceType::class, ['label' => 'Udogodnienia', 'choices' => self::choices($amenities), 'multiple' => true, 'expanded' => true])
            ->add('ageZones', CollectionType::class, self::collection(AgeZoneFormType::class, 'Strefy wieku', '__age_zone__'))
            ->add('openingHoursMode', EnumType::class, ['label' => 'Tryb godzin otwarcia', 'class' => OpeningHoursModeInput::class])
            ->add('weeklyOpeningHours', CollectionType::class, self::collection(WeeklyOpeningIntervalFormType::class, 'Godziny tygodniowe', '__weekly_interval__'))
            ->add('specialOpeningDays', CollectionType::class, self::collection(SpecialOpeningDayFormType::class, 'Dni specjalne', '__special_day__'))
            ->add('externalReferences', CollectionType::class, self::collection(ExternalReferenceFormType::class, 'Referencje zewnętrzne', '__external_reference__'))
            ->add('expectedVersion', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PlaceAdminFormData::class, 'csrf_protection' => true]);
    }

    /**
     * @param list<City|Category|Amenity> $items
     *
     * @return array<string, string>
     */
    private static function choices(array $items): array
    {
        $choices = [];
        foreach ($items as $item) {
            $choices[$item->name()] = $item->slug();
        }

        return $choices;
    }

    /** @return array<string, mixed> */
    private static function collection(string $entryType, string $label, string $prototypeName): array
    {
        return ['label' => $label, 'entry_type' => $entryType, 'allow_add' => true, 'allow_delete' => true, 'by_reference' => false, 'prototype' => true, 'prototype_name' => $prototypeName, 'error_bubbling' => false];
    }
}
