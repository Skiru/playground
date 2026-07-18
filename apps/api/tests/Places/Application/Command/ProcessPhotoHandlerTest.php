<?php

declare(strict_types=1);

namespace App\Tests\Places\Application\Command;

use App\Places\Application\Command\ProcessPhoto;
use App\Places\Application\Command\ProcessPhotoHandler;
use App\Places\Application\Command\ProcessPhotoFailureSubscriber;
use App\Places\Application\Command\FailPlacePhotoProcessing;
use App\Places\Application\PlaceCommandHandler;
use App\Places\Application\PlaceRepository;
use App\Places\Domain\Place;
use App\Places\Domain\PlacePhoto;
use App\Places\Domain\PlacePhotoStatus;
use App\Places\Domain\PlaceStatus;
use App\Places\Domain\VerificationStatus;
use App\Places\Domain\City;
use App\Places\Domain\Category;
use App\Places\Domain\ValueObject\PlaceName;
use App\Places\Domain\ValueObject\PlaceSlug;
use App\Places\Domain\ValueObject\Coordinates;
use App\Shared\Application\Clock;
use App\Shared\Application\Storage\ImageProcessor;
use App\Shared\Application\Storage\StorageInterface;
use App\Shared\Application\Storage\PermanentImageProcessingException;
use App\Shared\Application\Storage\UnsupportedImageException;
use App\Shared\Application\Storage\CorruptImageException;
use App\Shared\Application\Storage\StorageObjectNotFoundException;
use App\Shared\Application\Storage\TransientStorageException;
use App\Shared\Application\Storage\StorageConfigurationException;
use App\Shared\Application\TransactionManager;
use Symfony\Component\Messenger\MessageBusInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

final class ProcessPhotoHandlerTest extends TestCase
{
    private $connection;
    private $places;
    private $storage;
    private $processor;
    private $clock;
    private $logger;
    private $handler;
    private $realWebPBytes;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->places = $this->createMock(PlaceRepository::class);
        $this->storage = $this->createMock(StorageInterface::class);
        $this->processor = $this->createMock(ImageProcessor::class);
        $this->clock = $this->createMock(Clock::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-18T20:00:00Z'));

        $this->handler = new ProcessPhotoHandler(
            $this->connection,
            $this->places,
            $this->storage,
            $this->processor,
            $this->clock,
            $this->logger
        );

        // Generate actual valid WebP bytes
        $im = imagecreatetruecolor(10, 10);
        ob_start();
        imagewebp($im);
        $this->realWebPBytes = ob_get_clean();
    }

    private function createRealPlaceAndPhoto(string $placeId, string $photoId, int $generation = 1): array
    {
        $now = new \DateTimeImmutable('2026-07-18T19:00:00Z');
        $city = new City('Warszawa', 'warszawa', 'PL', new Coordinates(52.2, 21.0), 12, 15, 'Europe/Warsaw', true, $now);
        $primaryCategory = new Category('Parks', 'parks', null, 'parks', true, 1);
        
        $place = Place::reconstitute(
            Uuid::fromString($placeId),
            1,
            new PlaceName('C2R Admin Workflow'),
            new PlaceSlug('c2r-admin-workflow'),
            'Short description',
            'Description',
            'Address 1',
            '00-010',
            $city,
            'PL',
            new Coordinates(52.24, 21.02),
            'Europe/Warsaw',
            $primaryCategory,
            true,
            true,
            true,
            $now,
            PlaceStatus::DRAFT,
            VerificationStatus::UNVERIFIED,
            $now,
            null,
            null
        );

        $photo = PlacePhoto::reconstitute(
            Uuid::fromString($photoId),
            $place,
            'test.jpg',
            'places/' . $placeId . '/photos/' . $photoId . '/source',
            PlacePhotoStatus::QUEUED,
            true,
            0,
            null,
            null,
            null,
            $generation,
            null,
            null,
            $now,
            $now
        );

        $place->addPhoto($photo, $now);

        return [$place, $photo];
    }

    public function testCorruptJpegMarksAsFailedWithoutRetry(): void
    {
        $photoId = Uuid::v7()->toRfc4122();
        $placeId = Uuid::v7()->toRfc4122();

        $this->connection->method('fetchOne')->willReturn($placeId);

        [$place, $photo] = $this->createRealPlaceAndPhoto($placeId, $photoId);
        $this->places->method('get')->willReturn($place);

        $this->storage->method('read')->willReturn('corrupt bytes');
        $this->processor->method('resizeToWebp')->willThrowException(new CorruptImageException('Corrupt WebP/JPEG file'));

        $this->storage->expects(self::never())->method('write');
        $this->places->expects(self::atLeastOnce())->method('save');

        $this->handler->__invoke(new ProcessPhoto($photoId));

        self::assertSame(PlacePhotoStatus::FAILED, $photo->status());
        self::assertSame('CORRUPT_IMAGE', $photo->failureCode());
    }

    public function testUnsupportedInputMarksAsFailedWithoutRetry(): void
    {
        $photoId = Uuid::v7()->toRfc4122();
        $placeId = Uuid::v7()->toRfc4122();

        $this->connection->method('fetchOne')->willReturn($placeId);

        [$place, $photo] = $this->createRealPlaceAndPhoto($placeId, $photoId);
        $this->places->method('get')->willReturn($place);

        $this->storage->method('read')->willReturn('tiff bytes');
        $this->processor->method('resizeToWebp')->willThrowException(new UnsupportedImageException('Unsupported format: tiff'));

        $this->handler->__invoke(new ProcessPhoto($photoId));

        self::assertSame(PlacePhotoStatus::FAILED, $photo->status());
        self::assertSame('UNSUPPORTED_IMAGE', $photo->failureCode());
    }

    public function testLocalTemporaryWriteFailureThrowsException(): void
    {
        $photoId = Uuid::v7()->toRfc4122();
        $placeId = Uuid::v7()->toRfc4122();

        $this->connection->method('fetchOne')->willReturn($placeId);

        [$place, $photo] = $this->createRealPlaceAndPhoto($placeId, $photoId);
        $this->places->method('get')->willReturn($place);

        $this->storage->method('read')->willReturn('valid bytes');
        $this->processor->method('resizeToWebp')->willReturn($this->realWebPBytes);

        $this->storage->method('write')->willThrowException(new TransientStorageException('Local disk full or I/O error'));

        $this->expectException(TransientStorageException::class);

        $this->handler->__invoke(new ProcessPhoto($photoId));
    }

    public function testTransientS3503ThrowsException(): void
    {
        $photoId = Uuid::v7()->toRfc4122();
        $placeId = Uuid::v7()->toRfc4122();

        $this->connection->method('fetchOne')->willReturn($placeId);

        [$place, $photo] = $this->createRealPlaceAndPhoto($placeId, $photoId);
        $this->places->method('get')->willReturn($place);

        $this->storage->method('read')->willThrowException(new TransientStorageException('S3 503 Slow Down'));

        $this->expectException(TransientStorageException::class);

        $this->handler->__invoke(new ProcessPhoto($photoId));
    }

    public function testRetrySuccessOnSecondAttemptCompletesPhoto(): void
    {
        $photoId = Uuid::v7()->toRfc4122();
        $placeId = Uuid::v7()->toRfc4122();

        $this->connection->method('fetchOne')->willReturn($placeId);

        [$place, $photo] = $this->createRealPlaceAndPhoto($placeId, $photoId);
        $this->places->method('get')->willReturn($place);

        $this->storage->method('read')->willReturn('valid bytes');
        $this->processor->method('resizeToWebp')->willReturn($this->realWebPBytes);

        $this->handler->__invoke(new ProcessPhoto($photoId));

        self::assertSame(PlacePhotoStatus::COMPLETED, $photo->status());
        self::assertNull($photo->failureCode());
    }

    public function testRetriesExhaustedDispatchesFailPlacePhotoProcessing(): void
    {
        $photoId = Uuid::v7()->toRfc4122();
        $placeId = Uuid::v7()->toRfc4122();

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnMap([
            ['SELECT place_id FROM place_photos WHERE id = :id', ['id' => $photoId], $placeId],
            ['SELECT processing_generation FROM place_photos WHERE id = :id', ['id' => $photoId], 2]
        ]);

        $placesMock = $this->createMock(PlaceRepository::class);
        [$place, $photo] = $this->createRealPlaceAndPhoto($placeId, $photoId, 2);
        $placesMock->method('get')->willReturn($place);

        $transactionsMock = $this->createMock(TransactionManager::class);
        $transactionsMock->method('transactional')->willReturnCallback(static fn (callable $op) => $op());

        $clockMock = $this->createMock(Clock::class);
        $clockMock->method('now')->willReturn(new \DateTimeImmutable('2026-07-18T20:00:00Z'));

        $storageMock = $this->createMock(StorageInterface::class);
        $busMock = $this->createMock(MessageBusInterface::class);

        $commandHandler = new PlaceCommandHandler(
            $placesMock,
            $transactionsMock,
            $clockMock,
            $storageMock,
            $busMock
        );

        $placesMock->expects(self::once())->method('save')->with($place, 1);

        $subscriber = new ProcessPhotoFailureSubscriber($connection, $commandHandler);

        $event = new WorkerMessageFailedEvent(
            new Envelope(new ProcessPhoto($photoId)),
            'async',
            new \RuntimeException('Some persistent error')
        );

        $subscriber->onMessageFailed($event);

        self::assertSame(PlacePhotoStatus::FAILED, $photo->status());
        self::assertSame('PROCESSING_RETRIES_EXHAUSTED', $photo->failureCode());
    }

    public function testPartialVariantsAreCleanedUpOnFailure(): void
    {
        $photoId = Uuid::v7()->toRfc4122();
        $placeId = Uuid::v7()->toRfc4122();

        $this->connection->method('fetchOne')->willReturn($placeId);

        [$place, $photo] = $this->createRealPlaceAndPhoto($placeId, $photoId);
        $this->places->method('get')->willReturn($place);

        $this->storage->method('read')->willReturn('valid bytes');
        $this->processor->method('resizeToWebp')->willReturn($this->realWebPBytes);

        $callCount = 0;
        $writtenKeys = [];
        $this->storage->method('write')->willReturnCallback(function (string $path) use (&$callCount, &$writtenKeys) {
            $callCount++;
            if ($callCount === 2) {
                throw new TransientStorageException('Write failure');
            }
            $writtenKeys[] = $path;
        });

        $deletedKeys = [];
        $this->storage->method('delete')->willReturnCallback(function (string $path) use (&$deletedKeys) {
            $deletedKeys[] = $path;
        });

        try {
            $this->handler->__invoke(new ProcessPhoto($photoId));
            self::fail('Expected Exception');
        } catch (TransientStorageException) {
            self::assertCount(1, $writtenKeys);
            self::assertContains($writtenKeys[0], $deletedKeys);
        }
    }

    public function testManualReprocessAfterFailureIncrementsGenerationAndCompletes(): void
    {
        $photoId = Uuid::v7()->toRfc4122();
        $placeId = Uuid::v7()->toRfc4122();

        $this->connection->method('fetchOne')->willReturn($placeId);

        [$place, $photo] = $this->createRealPlaceAndPhoto($placeId, $photoId, 2);
        $this->places->method('get')->willReturn($place);

        $this->storage->method('read')->willReturn('valid bytes');
        $dummyWebP = "\x52\x49\x46\x46\x1a\x00\x00\x00\x57\x45\x42\x50\x56\x50\x38\x4c";
        $this->processor->method('resizeToWebp')->willReturn($this->realWebPBytes);

        $this->handler->__invoke(new ProcessPhoto($photoId));

        self::assertSame(PlacePhotoStatus::COMPLETED, $photo->status());
        self::assertSame(2, $photo->processingGeneration());
    }

    public function testFailureOfOldGenerationDoesNotAffectNewGeneration(): void
    {
        $photoId = Uuid::v7()->toRfc4122();
        $placeId = Uuid::v7()->toRfc4122();

        $this->connection->method('fetchOne')->willReturn($placeId);

        // Mock PlaceRepository to return generation 1 on the first get(), and generation 2 on subsequent get() (concurrent increment)
        $this->places->method('get')->willReturnCallback(function () use ($placeId, $photoId) {
            static $calls = 0;
            $calls++;
            $gen = min($calls, 2);
            [$place, $photo] = $this->createRealPlaceAndPhoto($placeId, $photoId, $gen);
            return $place;
        });

        $this->storage->method('read')->willReturn('valid bytes');
        $this->processor->method('resizeToWebp')->willReturn($this->realWebPBytes);

        // Invoke process photo for generation 1
        $this->handler->__invoke(new ProcessPhoto($photoId));

        // Let's load the second version (generation 2) to check it remained QUEUED (the race condition worked!)
        $finalPlace = $this->places->get($placeId);
        $finalPhoto = $finalPlace->photos()[0];
        
        self::assertSame(PlacePhotoStatus::QUEUED, $finalPhoto->status());
        self::assertSame(2, $finalPhoto->processingGeneration());
    }
}
