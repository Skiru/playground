<?php

declare(strict_types=1);

namespace App\Tests\Places\Application;

use App\Places\Application\Command\AgeZoneInput;
use App\Places\Application\Command\ArchivePlace;
use App\Places\Application\Command\CreatePlaceDraft;
use App\Places\Application\Command\ExternalReferenceInput;
use App\Places\Application\Command\MarkPlaceNeedsReverification;
use App\Places\Application\Command\MarkPlaceTemporarilyClosed;
use App\Places\Application\Command\PublishPlace;
use App\Places\Application\Command\ReplaceExternalReferences;
use App\Places\Application\Command\ReplacePlaceAgeZones;
use App\Places\Application\Command\ReplacePlaceAmenities;
use App\Places\Application\Command\ReplacePlaceCategories;
use App\Places\Application\Command\ReplaceSpecialOpeningDays;
use App\Places\Application\Command\ReplaceWeeklyOpeningHours;
use App\Places\Application\Command\SpecialOpeningDayInput;
use App\Places\Application\Command\SubmitPlaceForReview;
use App\Places\Application\Command\UnpublishPlace;
use App\Places\Application\Command\UpdatePlaceCoreDetails;
use App\Places\Application\Command\WeeklyOpeningIntervalInput;
use App\Places\Domain\VerificationStatus;
use PHPUnit\Framework\TestCase;

final class PlaceCommandsTest extends TestCase
{
    public function testEveryAdministrationCommandHasAnExplicitTypedContract(): void
    {
        $commands = [
            new CreatePlaceDraft('Place', 'place', 'Short', 'Description', 'Street 1', '00-001', 'warszawa', 'PL', 52.2, 21.0, 'Europe/Warsaw', 'parks', true, false, false),
            new UpdatePlaceCoreDetails('id', 1, 'Place', 'place', 'Short', 'Description', 'Street 1', '00-001', 'warszawa', 'PL', 52.2, 21.0, 'Europe/Warsaw', true, false, false, VerificationStatus::UNVERIFIED),
            new ReplacePlaceCategories('id', 1, ['parks'], 'parks'),
            new ReplacePlaceAmenities('id', 1, ['parking']),
            new ReplacePlaceAgeZones('id', 1, [new AgeZoneInput('Children', 12, 72)]),
            new ReplaceWeeklyOpeningHours('id', 1, [new WeeklyOpeningIntervalInput(1, 1, '09:00', '18:00', false)]),
            new ReplaceSpecialOpeningDays('id', 1, [new SpecialOpeningDayInput('2026-12-24', true, null, [])]),
            new ReplaceExternalReferences('id', 1, [new ExternalReferenceInput('osm', '123')]),
            new SubmitPlaceForReview('id', 1),
            new PublishPlace('id', 1),
            new UnpublishPlace('id', 1),
            new MarkPlaceNeedsReverification('id', 1),
            new MarkPlaceTemporarilyClosed('id', 1),
            new ArchivePlace('id', 1),
        ];

        self::assertCount(14, $commands);
    }

    public function testMutationCommandsRejectInvalidVersions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PublishPlace('id', 0);
    }
}
