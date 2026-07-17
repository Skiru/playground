<?php

declare(strict_types=1);

namespace App\Administration\UI\Form;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class AgeZoneFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $name = '';

    #[Assert\Range(min: 0, max: 216)]
    public int $minAgeMonths = 0;

    #[Assert\Range(min: 0, max: 216)]
    public ?int $maxAgeMonths = null;

    public ?string $notes = null;

    #[Assert\Callback]
    public function validateRange(ExecutionContextInterface $context): void
    {
        if (null !== $this->maxAgeMonths && $this->maxAgeMonths < $this->minAgeMonths) {
            $context->buildViolation('Maximum age must not be lower than minimum age.')->atPath('maxAgeMonths')->addViolation();
        }
    }
}
