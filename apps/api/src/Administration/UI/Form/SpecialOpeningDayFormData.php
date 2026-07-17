<?php

declare(strict_types=1);

namespace App\Administration\UI\Form;

use App\Places\Application\Command\SpecialOpeningDayModeInput;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SpecialOpeningDayFormData
{
    #[Assert\Date]
    public string $localDate = '';

    public SpecialOpeningDayModeInput $mode = SpecialOpeningDayModeInput::CLOSED;

    public ?string $note = null;

    /** @var list<SpecialOpeningIntervalFormData> */
    #[Assert\Valid]
    public array $intervals = [];

    #[Assert\Callback]
    public function validateMode(ExecutionContextInterface $context): void
    {
        if (SpecialOpeningDayModeInput::CUSTOM === $this->mode ? [] === $this->intervals : [] !== $this->intervals) {
            $context->buildViolation('Custom days require intervals; closed and 24-hour days cannot have intervals.')->atPath('intervals')->addViolation();
        }
    }
}
