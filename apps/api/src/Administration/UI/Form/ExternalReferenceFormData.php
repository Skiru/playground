<?php

declare(strict_types=1);

namespace App\Administration\UI\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class ExternalReferenceFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    public string $provider = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $externalId = '';

    #[Assert\Url(protocols: ['https'])]
    #[Assert\Length(max: 500)]
    public ?string $sourceUrl = null;
}
