<?php

declare(strict_types=1);

namespace App\Administration\UI\Form;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SpecialOpeningIntervalFormData
{
    #[Assert\Regex('/^(?:[01]\d|2[0-3]):[0-5]\d$/')]
    public string $opensAt = '09:00';

    #[Assert\Regex('/^(?:[01]\d|2[0-3]):[0-5]\d$/')]
    public string $closesAt = '18:00';

    public bool $closesNextDay = false;

    #[Assert\Callback]
    public function validateBoundary(ExecutionContextInterface $context): void
    {
        if (($this->closesNextDay && $this->closesAt > $this->opensAt) || (!$this->closesNextDay && $this->closesAt <= $this->opensAt)) {
            $context->buildViolation('Closing time and next-day selection are inconsistent.')->atPath('closesAt')->addViolation();
        }
    }
}
