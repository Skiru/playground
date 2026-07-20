<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Moderation\ContentReportRepository;
use App\Community\Domain\PlaceDiscussion\PlaceCommentRepository;
use App\Community\Domain\Review\ReviewRepository;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class GetModerationCase
{
    public function __construct(
        private readonly ContentReportRepository $reportRepository,
        private readonly ReviewRepository $reviewRepository,
        private readonly PlaceCommentRepository $commentRepository,
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumPostRepository $postRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Uuid $reportId): array
    {
        $report = $this->reportRepository->findById($reportId);
        if (null === $report) {
            throw new ApiException(404, 'Moderation case not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        $targetPreview = null;
        $targetId = $report->targetId();

        switch ($report->targetType()) {
            case \App\Community\Domain\Moderation\TargetType::REVIEW:
                $review = $this->reviewRepository->findById($targetId);
                if (null !== $review) {
                    $targetPreview = [
                        'title' => 'Opinia o miejscu',
                        'body' => $review->body(),
                        'rating' => $review->rating(),
                        'status' => $review->status()->value,
                    ];
                }
                break;

            case \App\Community\Domain\Moderation\TargetType::PLACE_COMMENT:
                $comment = $this->commentRepository->findById($targetId);
                if (null !== $comment) {
                    $targetPreview = [
                        'title' => 'Komentarz do miejsca',
                        'body' => $comment->body(),
                        'status' => $comment->status()->value,
                    ];
                }
                break;

            case \App\Community\Domain\Moderation\TargetType::FORUM_THREAD:
                $thread = $this->threadRepository->findById($targetId);
                if (null !== $thread) {
                    $targetPreview = [
                        'title' => 'Wątek na forum',
                        'body' => $thread->title(),
                        'status' => $thread->status()->value,
                    ];
                }
                break;

            case \App\Community\Domain\Moderation\TargetType::FORUM_POST:
                $post = $this->postRepository->findById($targetId);
                if (null !== $post) {
                    $targetPreview = [
                        'title' => 'Post na forum',
                        'body' => $post->body(),
                        'status' => $post->status()->value,
                    ];
                }
                break;
        }

        return [
            'id' => $report->id()->toString(),
            'reporterId' => $report->reporterId()->toString(),
            'targetId' => $report->targetId()->toString(),
            'targetType' => $report->targetType()->value,
            'reason' => $report->reason()->value,
            'details' => $report->details(),
            'status' => $report->status()->value,
            'createdAt' => $report->createdAt()->format(\DateTimeInterface::ATOM),
            'resolvedAt' => $report->resolvedAt()?->format(\DateTimeInterface::ATOM),
            'resolvedBy' => $report->resolvedBy()?->toString(),
            'targetPreview' => $targetPreview,
        ];
    }
}
