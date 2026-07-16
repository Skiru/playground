<?php

declare(strict_types=1);

namespace App\Tests\Administration\Infrastructure\Http;

use App\Administration\Infrastructure\Http\PlaceAdminController;
use PHPUnit\Framework\TestCase;

final class PlaceAdminArchitectureTest extends TestCase
{
    public function testFullEditUsesOnlyTheAggregateCommandWithoutVersionArithmetic(): void
    {
        $file = (new \ReflectionClass(PlaceAdminController::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertStringContainsString('new UpdatePlaceAggregate(', $source);
        self::assertStringNotContainsString('$version++', $source);
        self::assertStringNotContainsString('commands->edit(', $source);
        self::assertStringNotContainsString('new ReplacePlace', $source);
        self::assertStringNotContainsString('new ReplaceWeeklyOpeningHours', $source);
        self::assertStringNotContainsString('new ReplaceSpecialOpeningDays', $source);
        self::assertStringNotContainsString('new ReplaceExternalReferences', $source);
    }
}
