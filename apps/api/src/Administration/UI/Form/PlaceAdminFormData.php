<?php

declare(strict_types=1);

namespace App\Administration\UI\Form;

use App\Places\Application\Command\OpeningHoursModeInput;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class PlaceAdminFormData
{
    #[Assert\Valid]
    public PlaceCoreFormData $core;

    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'Select at least one category.')]
    public array $categorySlugs = [];

    #[Assert\NotBlank]
    public string $primaryCategorySlug = '';

    /** @var list<string> */
    public array $amenitySlugs = [];

    /** @var list<AgeZoneFormData> */
    #[Assert\Valid]
    public array $ageZones = [];

    public OpeningHoursModeInput $openingHoursMode = OpeningHoursModeInput::UNKNOWN;

    /** @var list<WeeklyOpeningIntervalFormData> */
    #[Assert\Valid]
    public array $weeklyOpeningHours = [];

    /** @var list<SpecialOpeningDayFormData> */
    #[Assert\Valid]
    public array $specialOpeningDays = [];

    /** @var list<ExternalReferenceFormData> */
    #[Assert\Valid]
    public array $externalReferences = [];

    public ?int $expectedVersion = null;

    public function __construct()
    {
        $this->core = new PlaceCoreFormData();
    }

    #[Assert\Callback]
    public function validateSelections(ExecutionContextInterface $context): void
    {
        if (!\in_array($this->primaryCategorySlug, $this->categorySlugs, true)) {
            $context->buildViolation('Primary category must be selected in categories.')->atPath('primaryCategorySlug')->addViolation();
        }
        if (OpeningHoursModeInput::SCHEDULED !== $this->openingHoursMode && [] !== $this->weeklyOpeningHours) {
            $context->buildViolation('Weekly intervals require scheduled mode.')->atPath('weeklyOpeningHours')->addViolation();
        }
        $this->validateWeeklyOverlap($context);
        $this->validateSpecialDays($context);
        $this->validateExternalReferences($context);
    }

    private function validateWeeklyOverlap(ExecutionContextInterface $context): void
    {
        $ranges = [];
        foreach ($this->weeklyOpeningHours as $index => $interval) {
            $start = (($interval->weekday - 1) * 1440) + self::minute($interval->opensAt);
            $end = (($interval->weekday - 1) * 1440) + self::minute($interval->closesAt) + ($interval->closesNextDay ? 1440 : 0);
            if ($end <= 10080) {
                $ranges[] = [$start, $end, $index];
            } else {
                $ranges[] = [$start, 10080, $index];
                $ranges[] = [0, $end - 10080, $index];
            }
        }
        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        foreach ($ranges as $index => $range) {
            if ($index > 0 && $ranges[$index - 1][1] > $range[0]) {
                $context->buildViolation('This interval overlaps another weekly interval.')->atPath('weeklyOpeningHours['.$range[2].'].opensAt')->addViolation();
            }
        }
    }

    private function validateSpecialDays(ExecutionContextInterface $context): void
    {
        $dates = [];
        $ranges = [];
        $timezone = timezone_open($this->core->timezone);
        foreach ($this->specialOpeningDays as $dayIndex => $day) {
            if (isset($dates[$day->localDate])) {
                $context->buildViolation('Special dates must be unique.')->atPath('specialOpeningDays['.$dayIndex.'].localDate')->addViolation();
            }
            $dates[$day->localDate] = true;
            if (!$timezone instanceof \DateTimeZone || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day->localDate)) {
                continue;
            }
            if (\App\Places\Application\Command\SpecialOpeningDayModeInput::OPEN_24_HOURS === $day->mode) {
                $start = new \DateTimeImmutable($day->localDate.' 00:00', $timezone);
                $ranges[] = [$start, $start->modify('+1 day'), $dayIndex, null];
                continue;
            }
            foreach ($day->intervals as $intervalIndex => $interval) {
                $start = new \DateTimeImmutable($day->localDate.' '.$interval->opensAt, $timezone);
                $endDate = $interval->closesNextDay ? $start->modify('+1 day')->format('Y-m-d') : $day->localDate;
                $end = new \DateTimeImmutable($endDate.' '.$interval->closesAt, $timezone);
                $ranges[] = [$start, $end, $dayIndex, $intervalIndex];
            }
        }
        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        foreach ($ranges as $index => $range) {
            if ($index > 0 && $ranges[$index - 1][1] > $range[0]) {
                $path = null === $range[3] ? 'specialOpeningDays['.$range[2].'].localDate' : 'specialOpeningDays['.$range[2].'].intervals['.$range[3].'].opensAt';
                $context->buildViolation('This special interval overlaps another special day interval.')->atPath($path)->addViolation();
            }
        }
    }

    private function validateExternalReferences(ExecutionContextInterface $context): void
    {
        $keys = [];
        foreach ($this->externalReferences as $index => $reference) {
            $key = $reference->provider."\0".$reference->externalId;
            if (isset($keys[$key])) {
                $context->buildViolation('Provider and external identifier must be unique.')->atPath('externalReferences['.$index.'].externalId')->addViolation();
            }
            $keys[$key] = true;
        }
    }

    private static function minute(string $time): int
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return 0;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }
}
