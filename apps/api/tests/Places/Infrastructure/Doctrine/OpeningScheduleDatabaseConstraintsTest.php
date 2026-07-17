<?php

declare(strict_types=1);

namespace App\Tests\Places\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpeningScheduleDatabaseConstraintsTest extends KernelTestCase
{
    private const string PLACE_ID = '00000000-0000-7000-8000-000000000400';

    public function testWeeklyDatabaseConstraintsRejectInvalidDaySequenceAndBoundaryCombinations(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $cases = [
            [0, 1, '09:00', '18:00', false],
            [1, 0, '09:00', '18:00', false],
            [1, 1, '18:00', '09:00', false],
            [1, 1, '09:00', '18:00', true],
        ];

        foreach ($cases as $index => [$weekday, $sequence, $opens, $closes, $nextDay]) {
            try {
                $connection->insert('weekly_opening_intervals', ['id' => self::id(100 + $index), 'place_id' => self::PLACE_ID, 'weekday' => $weekday, 'sequence' => $sequence, 'opens_at' => $opens, 'closes_at' => $closes, 'closes_next_day' => (int) $nextDay]);
                self::fail('Invalid weekly interval must violate a database constraint.');
            } catch (\Doctrine\DBAL\Exception $exception) {
                self::assertStringContainsString('Check violation', $exception->getMessage());
            }
        }
    }

    public function testNonCustomSpecialDayCannotPersistIntervals(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $dayId = self::id(200);
        $connection->delete('special_opening_days', ['id' => $dayId]);

        $connection->beginTransaction();
        try {
            $connection->insert('special_opening_days', ['id' => $dayId, 'place_id' => self::PLACE_ID, 'local_date' => '2030-01-01', 'mode' => 'closed', 'note' => null]);
            $connection->insert('special_opening_intervals', ['id' => self::id(201), 'special_opening_day_id' => $dayId, 'sequence' => 1, 'opens_at' => '09:00', 'closes_at' => '10:00', 'closes_next_day' => 0]);
            $connection->commit();
            self::fail('A non-custom special day with intervals must be rejected at commit.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('inconsistent', $exception->getMessage());
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM special_opening_days WHERE id=:id', ['id' => $dayId]));
        }
    }

    private static function id(int $number): string
    {
        return \sprintf('00000000-0000-7000-b000-%012d', $number);
    }
}
