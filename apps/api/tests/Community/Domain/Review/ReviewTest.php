<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain\Review;

use App\Community\Domain\Review\Review;
use App\Community\Domain\Review\ReviewStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReviewTest extends TestCase
{
    public function testCreateValidReview(): void
    {
        $id = Uuid::v7();
        $placeId = Uuid::v7();
        $authorId = Uuid::v7();
        $now = new \DateTimeImmutable();

        $review = new Review(
            $id,
            $placeId,
            $authorId,
            5,
            'This is a wonderful place with great customer service and clean play areas.',
            null,
            ReviewStatus::PUBLISHED,
            $now,
            $now
        );

        self::assertSame($id, $review->id());
        self::assertSame($placeId, $review->placeId());
        self::assertSame($authorId, $review->authorId());
        self::assertSame(5, $review->rating());
        self::assertSame('This is a wonderful place with great customer service and clean play areas.', $review->body());
        self::assertNull($review->visitedOn());
        self::assertSame(ReviewStatus::PUBLISHED, $review->status());
        self::assertSame($now, $review->createdAt());
    }

    public function testRatingBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Review(Uuid::v7(), Uuid::v7(), Uuid::v7(), 6, 'A valid body text with more than twenty characters.', null, ReviewStatus::PUBLISHED, new \DateTimeImmutable(), new \DateTimeImmutable());
    }

    public function testBodyTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Review(Uuid::v7(), Uuid::v7(), Uuid::v7(), 5, 'Too short', null, ReviewStatus::PUBLISHED, new \DateTimeImmutable(), new \DateTimeImmutable());
    }
}
