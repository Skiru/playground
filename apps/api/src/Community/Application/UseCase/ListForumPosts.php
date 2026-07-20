<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\PublicAuthorProfileLookup;
use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumPostStatus;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class ListForumPosts
{
    public function __construct(
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly PublicAuthorProfileLookup $authorProfileLookup,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Uuid $threadId, int $limit, ?string $cursorStr): array
    {
        $thread = $this->threadRepository->findById($threadId);
        if (null === $thread || ForumThreadStatus::HIDDEN === $thread->status() || ForumThreadStatus::REMOVED_BY_MODERATOR === $thread->status()) {
            throw new ApiException(404, 'Thread not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        // Decode cursor
        $cursorId = null;
        $cursorCreatedAt = null;

        if (null !== $cursorStr && '' !== $cursorStr) {
            $decoded = json_decode((string) base64_decode($cursorStr), true);
            if (\is_array($decoded)) {
                $cursorId = $decoded['id'] ?? null;
                $cursorCreatedAt = isset($decoded['createdAt']) ? new \DateTimeImmutable($decoded['createdAt']) : null;
            }
        }

        // Fetch posts (+1 to check for next page)
        $posts = $this->postRepository->findByThreadId($threadId, $cursorId, $cursorCreatedAt, $limit + 1);

        $hasNextPage = \count($posts) > $limit;
        if ($hasNextPage) {
            array_pop($posts);
        }

        // Batch load profiles to avoid N+1
        $authorIds = array_map(static fn ($p) => $p->authorId(), $posts);
        $profiles = $this->authorProfileLookup->getProfiles($authorIds);

        $items = [];
        foreach ($posts as $post) {
            $authorIdStr = $post->authorId()->toString();

            if (ForumPostStatus::DELETED_BY_AUTHOR === $post->status()) {
                $author = [
                    'id' => $authorIdStr,
                    'displayName' => 'Usunięty użytkownik',
                    'initials' => 'U',
                ];
                $body = 'Treść usunięta przez autora';
            } else {
                $author = $profiles[$authorIdStr] ?? [
                    'id' => $authorIdStr,
                    'displayName' => 'Usunięty użytkownik',
                    'initials' => 'U',
                ];
                $body = $post->body();
            }

            $items[] = [
                'id' => $post->id()->toString(),
                'threadId' => $post->threadId()->toString(),
                'authorId' => $authorIdStr,
                'author' => $author,
                'parentId' => $post->parentId()?->toString(),
                'body' => $body,
                'status' => $post->status()->value,
                'createdAt' => $post->createdAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $post->updatedAt()->format(\DateTimeInterface::ATOM),
                'version' => $post->version(),
            ];
        }

        $nextCursor = null;
        if (!empty($posts)) {
            $lastPost = end($posts);
            $nextCursor = base64_encode((string) json_encode([
                'id' => $lastPost->id()->toString(),
                'createdAt' => $lastPost->createdAt()->format('Y-m-d H:i:s'),
            ]));
        }

        return [
            'items' => $items,
            'pagination' => [
                'nextCursor' => $nextCursor,
                'hasNextPage' => $hasNextPage,
            ],
        ];
    }
}
