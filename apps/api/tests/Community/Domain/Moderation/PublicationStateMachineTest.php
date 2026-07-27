<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain\Moderation;

use App\Community\Domain\Forum\ForumPost;
use App\Community\Domain\Forum\ForumPostStatus;
use App\Community\Domain\Forum\ForumThread;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Community\Domain\PlaceDiscussion\PlaceComment;
use App\Community\Domain\PlaceDiscussion\PlaceCommentStatus;
use App\Community\Domain\Review\Review;
use App\Community\Domain\Review\ReviewStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PublicationStateMachineTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, bool}> */
    public static function transitions(): iterable
    {
        foreach (['review', 'comment', 'thread', 'post'] as $target) {
            foreach (['PUBLISHED', 'HIDDEN', 'REMOVED_BY_MODERATOR', 'DELETED_BY_AUTHOR'] as $status) {
                foreach (['hide', 'removeByModerator', 'publish'] as $action) {
                    $allowed = ('PUBLISHED' === $status && \in_array($action, ['hide', 'removeByModerator'], true))
                        || ('HIDDEN' === $status && 'publish' === $action);
                    yield "{$target} {$status} {$action}" => [$target, $status, $action, $allowed];
                }
            }
        }
    }

    #[DataProvider('transitions')]
    public function testPublicationTransitions(string $target, string $status, string $action, bool $allowed): void
    {
        $content = $this->content($target, $status);
        $now = new \DateTimeImmutable('2026-07-25 08:00:00');

        if (!$allowed) {
            $this->expectException(\LogicException::class);
        }

        $content->{$action}($now);

        if ($allowed) {
            $expected = match ($action) {
                'hide' => 'HIDDEN',
                'removeByModerator' => 'REMOVED_BY_MODERATOR',
                'publish' => 'PUBLISHED',
            };
            self::assertSame($expected, $content->status()->value);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function targets(): iterable
    {
        foreach (['review', 'comment', 'thread', 'post'] as $target) {
            yield $target => [$target];
        }
    }

    #[DataProvider('targets')]
    public function testHiddenAuthorCanDeleteButModeratorCannotRestore(string $target): void
    {
        $content = $this->content($target, 'HIDDEN');
        $content->softDelete(new \DateTimeImmutable('2026-07-25 08:00:00'));
        self::assertSame('DELETED_BY_AUTHOR', $content->status()->value);

        $this->expectException(\LogicException::class);
        $content->publish(new \DateTimeImmutable('2026-07-25 08:01:00'));
    }

    private function content(string $target, string $status): Review|PlaceComment|ForumThread|ForumPost
    {
        $id = Uuid::v7();
        $parentId = Uuid::v7();
        $now = new \DateTimeImmutable('2026-07-25 07:00:00');

        return match ($target) {
            'review' => new Review($id, Uuid::v7(), Uuid::v7(), 4, 'A sufficiently long review body.', null, ReviewStatus::from($status), $now, $now),
            'comment' => new PlaceComment($id, Uuid::v7(), Uuid::v7(), null, 'Comment body', PlaceCommentStatus::from($status), $now, $now),
            'thread' => new ForumThread($id, Uuid::v7(), Uuid::v7(), 'Thread title', ForumThreadStatus::from($status), $now, $now, $now),
            'post' => new ForumPost($id, Uuid::v7(), Uuid::v7(), $parentId, 'Post body', ForumPostStatus::from($status), $now, $now),
        };
    }
}
