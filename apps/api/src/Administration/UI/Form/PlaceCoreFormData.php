<?php

declare(strict_types=1);

namespace App\Administration\UI\Form;

use App\Places\Application\Command\VerificationStatusInput;
use Symfony\Component\Validator\Constraints as Assert;

final class PlaceCoreFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    #[Assert\Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
    public string $slug = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 300)]
    public string $shortDescription = '';

    #[Assert\NotBlank]
    public string $description = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    public string $addressLine1 = '';

    #[Assert\Length(max: 180)]
    public ?string $addressLine2 = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    public string $postalCode = '';

    #[Assert\NotBlank]
    public string $citySlug = '';

    #[Assert\Regex('/^[A-Z]{2}$/')]
    public string $countryCode = 'PL';

    #[Assert\Range(min: -90, max: 90)]
    public float $latitude = 0.0;

    #[Assert\Range(min: -180, max: 180)]
    public float $longitude = 0.0;

    #[Assert\Timezone]
    public string $timezone = 'Europe/Warsaw';

    public bool $indoor = false;
    public bool $outdoor = false;
    public bool $freeEntry = false;

    #[Assert\Length(max: 255)]
    public ?string $priceDescription = null;

    #[Assert\Url(protocols: ['https'])]
    #[Assert\Length(max: 500)]
    public ?string $websiteUrl = null;

    #[Assert\Length(max: 40)]
    public ?string $phone = null;

    public VerificationStatusInput $verificationStatus = VerificationStatusInput::UNVERIFIED;
}
