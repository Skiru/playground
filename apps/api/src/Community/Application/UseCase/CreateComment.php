<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\PublishedPlaceLookup;
use App\Community\Domain\PlaceDiscussion\PlaceComment;
use App\Community\Domain\PlaceDiscussion\PlaceCommentRepository;
use App\Community\Domain\PlaceDiscussion\PlaceCommentStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class CreateComment
{
    public function __construct(
        private readonly PlaceCommentRepository $placeCommentRepository,
        private readonly PublishedPlaceLookup $publishedPlaceLookup,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $placeId, string $body): PlaceComment
    {
        if (!$this->publishedPlaceLookup->isPublished($placeId)) {
            throw new ApiException(404, 'Place not found or not published.', 'MISSING_PUBLIC_RESOURCE');
        }

        $now = $this->clock->now();
        $comment = new PlaceComment(
            Uuid::v7(),
            $placeId,
            $userId,
            null,
            $body,
            PlaceCommentStatus::PUBLISHED,
            $now,
            $now
        );

        $this->placeCommentRepository->save($comment);

        return $comment;
    }
}
