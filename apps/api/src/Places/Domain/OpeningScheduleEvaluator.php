<?php

declare(strict_types=1);

namespace App\Places\Domain;

final class OpeningScheduleEvaluator
{
    /**
     * @param list<WeeklyOpeningInterval> $weeklyIntervals
     * @param list<SpecialOpeningDay>     $specialDays
     */
    public function evaluate(OpeningHoursMode $mode, string $timezone, array $weeklyIntervals, array $specialDays, \DateTimeImmutable $instant): OpeningScheduleEvaluation
    {
        if (OpeningHoursMode::UNKNOWN === $mode) {
            return new OpeningScheduleEvaluation(OpeningState::UNKNOWN, 'No reliable opening schedule is available.', 'mode', null);
        }

        $zone = new \DateTimeZone($timezone);
        $local = $instant->setTimezone($zone);
        $date = $local->format('Y-m-d');
        $specialByDate = [];
        foreach ($specialDays as $day) {
            $specialByDate[$day->localDate()->format('Y-m-d')] = $day;
        }

        if (isset($specialByDate[$date])) {
            return $this->evaluateSpecialDay($specialByDate[$date], $local, $zone);
        }

        $previousDate = $local->modify('-1 day')->format('Y-m-d');
        if (isset($specialByDate[$previousDate]) && SpecialOpeningDayMode::CUSTOM === $specialByDate[$previousDate]->mode()) {
            foreach ($specialByDate[$previousDate]->intervals() as $interval) {
                if (!$interval->closesNextDay()) {
                    continue;
                }
                [$start, $end] = $this->specialRange($specialByDate[$previousDate], $interval, $zone);
                if ($local >= $start && $local < $end) {
                    return new OpeningScheduleEvaluation(OpeningState::OPEN, 'Carried over from the previous special day.', 'special', $end);
                }
            }
        }

        if (OpeningHoursMode::ALWAYS_OPEN === $mode) {
            return new OpeningScheduleEvaluation(OpeningState::OPEN, 'Place is configured as always open.', 'mode', $this->nextSpecialMidnight($specialDays, $local, $zone));
        }

        foreach ($weeklyIntervals as $interval) {
            if ($this->weeklyContains($interval, $local)) {
                [, $end] = $this->weeklyRange($interval, $local, $zone);

                return new OpeningScheduleEvaluation(OpeningState::OPEN, 'Current instant is inside a weekly interval.', 'weekly', $end);
            }
        }

        return new OpeningScheduleEvaluation(OpeningState::CLOSED, 'Current instant is outside all weekly intervals.', 'weekly', $this->nextWeeklyOpening($weeklyIntervals, $local, $zone));
    }

    private function evaluateSpecialDay(SpecialOpeningDay $day, \DateTimeImmutable $local, \DateTimeZone $zone): OpeningScheduleEvaluation
    {
        $nextMidnight = $this->local($local->modify('+1 day')->format('Y-m-d'), '00:00', $zone);
        if (SpecialOpeningDayMode::CLOSED === $day->mode()) {
            return new OpeningScheduleEvaluation(OpeningState::CLOSED, 'The special day is closed.', 'special', $nextMidnight);
        }
        if (SpecialOpeningDayMode::OPEN_24_HOURS === $day->mode()) {
            return new OpeningScheduleEvaluation(OpeningState::OPEN, 'The special day is open for 24 hours.', 'special', $nextMidnight);
        }

        $next = $nextMidnight;
        foreach ($day->intervals() as $interval) {
            [$start, $end] = $this->specialRange($day, $interval, $zone);
            if ($this->specialContainsCurrentDay($interval, $local)) {
                return new OpeningScheduleEvaluation(OpeningState::OPEN, 'Current instant is inside a custom special interval.', 'special', $end);
            }
            if ($start > $local && $start < $next) {
                $next = $start;
            }
        }

        return new OpeningScheduleEvaluation(OpeningState::CLOSED, 'Current instant is outside custom special intervals.', 'special', $next);
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function specialRange(SpecialOpeningDay $day, SpecialOpeningInterval $interval, \DateTimeZone $zone): array
    {
        $date = $day->localDate()->format('Y-m-d');
        $endDate = $interval->closesNextDay() ? (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') : $date;

        return [$this->local($date, $interval->opensAt()->format('H:i'), $zone), $this->local($endDate, $interval->closesAt()->format('H:i'), $zone)];
    }

    private function specialContainsCurrentDay(SpecialOpeningInterval $interval, \DateTimeImmutable $local): bool
    {
        $minute = ((int) $local->format('H') * 60) + (int) $local->format('i');
        $opens = ((int) $interval->opensAt()->format('H') * 60) + (int) $interval->opensAt()->format('i');
        $closes = ((int) $interval->closesAt()->format('H') * 60) + (int) $interval->closesAt()->format('i');

        return $interval->closesNextDay() ? $minute >= $opens : $minute >= $opens && $minute < $closes;
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function weeklyRange(WeeklyOpeningInterval $interval, \DateTimeImmutable $local, \DateTimeZone $zone): array
    {
        $daysBack = ((int) $local->format('N') - $interval->weekday() + 7) % 7;
        $date = $local->modify('-'.$daysBack.' days')->format('Y-m-d');
        $start = $this->local($date, $interval->opensAt()->format('H:i'), $zone);
        $endDate = $interval->closesNextDay() ? $start->modify('+1 day')->format('Y-m-d') : $date;

        return [$start, $this->local($endDate, $interval->closesAt()->format('H:i'), $zone)];
    }

    private function weeklyContains(WeeklyOpeningInterval $interval, \DateTimeImmutable $local): bool
    {
        $current = (((int) $local->format('N') - 1) * 1440) + ((int) $local->format('H') * 60) + (int) $local->format('i');
        $start = (($interval->weekday() - 1) * 1440) + ((int) $interval->opensAt()->format('H') * 60) + (int) $interval->opensAt()->format('i');
        $end = (($interval->weekday() - 1) * 1440) + ((int) $interval->closesAt()->format('H') * 60) + (int) $interval->closesAt()->format('i') + ($interval->closesNextDay() ? 1440 : 0);
        if ($end <= 10080) {
            return $current >= $start && $current < $end;
        }

        return $current >= $start || $current < $end - 10080;
    }

    /** @param list<WeeklyOpeningInterval> $intervals */
    private function nextWeeklyOpening(array $intervals, \DateTimeImmutable $local, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        $next = null;
        foreach ($intervals as $interval) {
            for ($offset = 0; $offset <= 7; ++$offset) {
                $date = $local->modify('+'.$offset.' days');
                if ((int) $date->format('N') !== $interval->weekday()) {
                    continue;
                }
                $candidate = $this->local($date->format('Y-m-d'), $interval->opensAt()->format('H:i'), $zone);
                if ($candidate > $local && (null === $next || $candidate < $next)) {
                    $next = $candidate;
                }
            }
        }

        return $next;
    }

    /** @param list<SpecialOpeningDay> $days */
    private function nextSpecialMidnight(array $days, \DateTimeImmutable $local, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        $next = null;
        foreach ($days as $day) {
            $candidate = $this->local($day->localDate()->format('Y-m-d'), '00:00', $zone);
            if ($candidate > $local && (null === $next || $candidate < $next)) {
                $next = $candidate;
            }
        }

        return $next;
    }

    private function local(string $date, string $time, \DateTimeZone $zone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date.' '.$time, $zone);
    }
}
