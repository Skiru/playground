<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\PublicAuthorProfileLookup;
use App\Community\Domain\Forum\ForumCategoryRepository;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Shared\Application\Exception\ApiException;

final class ListCategoryThreads
{
    public function __construct(
        private readonly ForumCategoryRepository $categoryRepository,
        private readonly ForumThreadRepository $threadRepository,
        private readonly PublicAuthorProfileLookup $authorProfileLookup,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $slug, int $limit, ?string $cursorStr): array
    {
        $category = $this->categoryRepository->findBySlug($slug);
        if (null === $category || !$category->isActive()) {
            throw new ApiException(404, 'Category not found or inactive.', 'MISSING_PUBLIC_RESOURCE');
        }

        // Decode cursor
        $cursorId = null;
        $cursorPinnedAt = null;
        $cursorLastActivityAt = null;

        if (null !== $cursorStr && '' !== $cursorStr) {
            $decoded = json_decode((string) base64_decode($cursorStr), true);
            if (\is_array($decoded)) {
                $cursorId = $decoded['id'] ?? null;
                $cursorPinnedAt = isset($decoded['pinnedAt']) ? new \DateTimeImmutable($decoded['pinnedAt']) : null;
                $cursorLastActivityAt = isset($decoded['lastActivityAt']) ? new \DateTimeImmutable($decoded['lastActivityAt']) : null;
            }
        }

        // Fetch threads (+1 to check for next page)
        $threads = $this->threadRepository->findByCategoryId(
            $category->id(),
            $cursorId,
            $cursorPinnedAt,
            $cursorLastActivityAt,
            $limit + 1
        );

        $hasNextPage = \count($threads) > $limit;
        if ($hasNextPage) {
            array_pop($threads);
        }

        // Batch load profiles to avoid N+1
        $authorIds = array_map(static fn ($t) => $t->authorId(), $threads);
        $profiles = $this->authorProfileLookup->getProfiles($authorIds);

        $items = [];
        foreach ($threads as $thread) {
            $authorIdStr = $thread->authorId()->toString();

            if (ForumThreadStatus::DELETED_BY_AUTHOR === $thread->status()) {
                $author = [
                    'id' => $authorIdStr,
                    'displayName' => 'Usunięty użytkownik',
                    'initials' => 'U',
                ];
                $title = 'Wątek usunięty przez autora';
            } else {
                $author = $profiles[$authorIdStr] ?? [
                    'id' => $authorIdStr,
                    'displayName' => 'Usunięty użytkownik',
                    'initials' => 'U',
                ];
                $title = $thread->title();
            }

            $items[] = [
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
            ];
        }

        $nextCursor = null;
        if (!empty($threads)) {
            $lastThread = end($threads);
            $nextCursor = base64_encode((string) json_encode([
                'id' => $lastThread->id()->toString(),
                'pinnedAt' => $lastThread->pinnedAt()?->format('Y-m-d H:i:s'),
                'lastActivityAt' => $lastThread->lastActivityAt()->format('Y-m-d H:i:s'),
            ]));
        }

        return [
            'category' => [
                'id' => $category->id()->toString(),
                'slug' => $category->slug(),
                'name' => $category->name(),
                'description' => $category->description(),
            ],
            'items' => $items,
            'pagination' => [
                'nextCursor' => $nextCursor,
                'hasNextPage' => $hasNextPage,
            ],
        ];
    }
}
