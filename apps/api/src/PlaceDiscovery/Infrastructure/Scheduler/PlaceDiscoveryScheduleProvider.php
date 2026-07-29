<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Infrastructure\Scheduler;

use App\PlaceDiscovery\Application\Message\CheckLatestPlaceSourceRelease;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('place_discovery')]
final readonly class PlaceDiscoveryScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::cron('17 3 * * *', new CheckLatestPlaceSourceRelease(), new \DateTimeZone('Europe/Warsaw')));
    }
}
