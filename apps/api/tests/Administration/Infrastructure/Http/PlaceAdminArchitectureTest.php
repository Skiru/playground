<?php

declare(strict_types=1);

namespace App\Tests\Administration\Infrastructure\Http;

use App\Administration\Infrastructure\Http\PlaceAdminController;
use App\Administration\UI\Form\PlaceAdminCommandFactory;
use App\Administration\UI\Form\PlaceAdminFormData;
use PHPUnit\Framework\TestCase;

final class PlaceAdminArchitectureTest extends TestCase
{
    public function testFullEditUsesOnlyTheAggregateCommandWithoutVersionArithmetic(): void
    {
        $file = (new \ReflectionClass(PlaceAdminController::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        $factoryFile = (new \ReflectionClass(PlaceAdminCommandFactory::class))->getFileName();
        self::assertIsString($factoryFile);
        $factorySource = file_get_contents($factoryFile);
        self::assertIsString($factorySource);
        self::assertStringContainsString('new UpdatePlaceAggregate(', $factorySource);
        self::assertStringContainsString('commandFactory->update(', $source);
        self::assertStringNotContainsString('$version++', $source);
        self::assertStringNotContainsString('commands->edit(', $source);
        self::assertStringNotContainsString('new ReplacePlace', $source);
        self::assertStringNotContainsString('new ReplaceWeeklyOpeningHours', $source);
        self::assertStringNotContainsString('new ReplaceSpecialOpeningDays', $source);
        self::assertStringNotContainsString('new ReplaceExternalReferences', $source);
        self::assertStringNotContainsString('function csv(', $source);
        self::assertStringNotContainsString('function ageZones(', $source);
        self::assertStringNotContainsString('function weeklyHours(', $source);
        self::assertStringNotContainsString('function specialDays(', $source);
        self::assertStringNotContainsString('function externalReferences(', $source);
        self::assertStringNotContainsString('function lines(', $source);
        self::assertStringNotContainsString('explode(', $source);
        self::assertStringNotContainsString('Doctrine\\ORM', (string) file_get_contents((new \ReflectionClass(PlaceAdminFormData::class))->getFileName()));
    }
}
