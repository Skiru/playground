<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumCategoryRepository;
use App\Community\Domain\Forum\ForumPost;
use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumPostStatus;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use App\Shared\Application\TransactionManager;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Uid\Uuid;

final class CreateForumPost
{
    public function __construct(
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumCategoryRepository $categoryRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly TransactionManager $transactionManager,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $threadId, ?Uuid $replyToPostId, string $body): ForumPost
    {
        try {
            return $this->transactionManager->transactional(function () use ($userId, $threadId, $replyToPostId, $body): ForumPost {
                // Lock order is thread, then parent post. Moderation and delete flows use the same order.
                $thread = $this->threadRepository->findByIdForUpdate($threadId);
                if (null === $thread || ForumThreadStatus::PUBLISHED !== $thread->status()) {
                    throw new ApiException(404, 'Thread not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                if (null !== $thread->lockedAt()) {
                    throw new ApiException(409, 'Thread is locked.', 'THREAD_WRITE_CONFLICT');
                }

                $category = $this->categoryRepository->findById($thread->categoryId());
                if (null === $category || !$category->isActive()) {
                    throw new ApiException(400, 'Category is inactive.', 'INACTIVE_CATEGORY');
                }

                if (null !== $replyToPostId) {
                    $parentPost = $this->postRepository->findByIdForUpdate($replyToPostId);
                    if (null === $parentPost || $parentPost->threadId()->toRfc4122() !== $threadId->toRfc4122()) {
                        throw new ApiException(400, 'Replied-to post must belong to the same thread.', 'INVALID_PARENT_POST');
                    }
                    if (null !== $parentPost->parentId()) {
                        throw new ApiException(400, 'Do not allow a reply to a reply.', 'FORUM_REPLY_DEPTH_LIMIT');
                    }
                    if (ForumPostStatus::PUBLISHED !== $parentPost->status()) {
                        throw new ApiException(400, 'Cannot reply to a non-public post.', 'INVALID_PARENT_STATUS');
                    }
                }

                $now = $this->clock->now();
                $post = new ForumPost(Uuid::v7(), $threadId, $userId, $replyToPostId, $body, ForumPostStatus::PUBLISHED, $now, $now);
                $thread->updateLastActivity($now);
                $this->threadRepository->save($thread);
                $this->postRepository->save($post);

                return $post;
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            if ('CONCURRENCY_ERROR' === $e->getMessage()) {
                throw new ApiException(409, 'The thread changed while the post was being created.', 'THREAD_WRITE_CONFLICT', previous: $e);
            }
            throw $e;
        } catch (DbalException $e) {
            throw new ApiException(409, 'The thread changed while the post was being created.', 'THREAD_WRITE_CONFLICT', previous: $e);
        }
    }
}
