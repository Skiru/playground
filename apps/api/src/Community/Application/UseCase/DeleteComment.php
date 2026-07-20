<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\PlaceDiscussion\PlaceCommentRepository;
use App\Community\Domain\PlaceDiscussion\PlaceCommentStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class DeleteComment
{
    public function __construct(
        private readonly PlaceCommentRepository $placeCommentRepository,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $commentId): void
    {
        $comment = $this->placeCommentRepository->findById($commentId);
        if (null === $comment || PlaceCommentStatus::DELETED_BY_AUTHOR === $comment->status() || PlaceCommentStatus::REMOVED_BY_MODERATOR === $comment->status()) {
            throw new ApiException(404, 'Comment not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        if ($comment->authorId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new ApiException(403, 'You cannot delete someone else\'s comment.', 'FORBIDDEN_OWNERSHIP');
        }

        $comment->softDelete($this->clock->now());
        $this->placeCommentRepository->save($comment);
    }
}
