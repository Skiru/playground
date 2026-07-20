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

final class CreateReply
{
    public function __construct(
        private readonly PlaceCommentRepository $placeCommentRepository,
        private readonly PublishedPlaceLookup $publishedPlaceLookup,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $parentCommentId, string $body): PlaceComment
    {
        $parent = $this->placeCommentRepository->findById($parentCommentId);
        if (null === $parent) {
            throw new ApiException(404, 'Parent comment not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        // Parent must belong to a published place
        if (!$this->publishedPlaceLookup->isPublished($parent->placeId())) {
            throw new ApiException(404, 'Place not found or not published.', 'MISSING_PUBLIC_RESOURCE');
        }

        // Parent must have no parent (no reply to a reply)
        if (null !== $parent->parentId()) {
            throw new ApiException(400, 'Do not allow a reply to a reply.', 'COMMENT_REPLY_DEPTH_LIMIT');
        }

        // Parent must be currently PUBLISHED
        if (PlaceCommentStatus::PUBLISHED !== $parent->status()) {
            throw new ApiException(400, 'Cannot reply to a non-public parent comment.', 'INVALID_PARENT_STATUS');
        }

        $now = $this->clock->now();
        $reply = new PlaceComment(
            Uuid::v7(),
            $parent->placeId(),
            $userId,
            $parent->id(),
            $body,
            PlaceCommentStatus::PUBLISHED,
            $now,
            $now
        );

        $this->placeCommentRepository->save($reply);

        return $reply;
    }
}
