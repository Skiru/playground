<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\PublicAuthorProfileLookup;
use App\Community\Domain\Forum\ForumCategoryRepository;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class GetForumThread
{
    public function __construct(
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumCategoryRepository $categoryRepository,
        private readonly PublicAuthorProfileLookup $authorProfileLookup,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Uuid $threadId): array
    {
        $thread = $this->threadRepository->findById($threadId);
        if (null === $thread || ForumThreadStatus::HIDDEN === $thread->status() || ForumThreadStatus::REMOVED_BY_MODERATOR === $thread->status()) {
            throw new ApiException(404, 'Thread not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        $category = $this->categoryRepository->findById($thread->categoryId());
        if (null === $category || !$category->isActive()) {
            throw new ApiException(404, 'Category not found or inactive.', 'MISSING_PUBLIC_RESOURCE');
        }

        $authorProfile = $this->authorProfileLookup->getProfile($thread->authorId());

        $authorIdStr = $thread->authorId()->toString();
        if (ForumThreadStatus::DELETED_BY_AUTHOR === $thread->status()) {
            $author = [
                'id' => $authorIdStr,
                'displayName' => 'Usunięty użytkownik',
                'initials' => 'U',
            ];
            $title = 'Wątek usunięty przez autora';
        } else {
            $author = $authorProfile ?? [
                'id' => $authorIdStr,
                'displayName' => 'Usunięty użytkownik',
                'initials' => 'U',
            ];
            $title = $thread->title();
        }

        return [
            'id' => $thread->id()->toString(),
            'categoryId' => $thread->categoryId()->toString(),
            'authorId' => $authorIdStr,
            'author' => $author,
            'title' => $title,
            'status' => $thread->status()->value,
            'createdAt' => $thread->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $thread->updatedAt()->format(\DateTimeInterface::ATOM),
            'lastActivityAt' => $thread->lastActivityAt()->format(\DateTimeInterface::ATOM),
            'lockedAt' => $thread->lockedAt()?->format(\DateTimeInterface::ATOM),
            'pinnedAt' => $thread->pinnedAt()?->format(\DateTimeInterface::ATOM),
            'version' => $thread->version(),
            'category' => [
                'id' => $category->id()->toString(),
                'slug' => $category->slug(),
                'name' => $category->name(),
            ],
        ];
    }
}
