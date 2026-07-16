<?php

declare(strict_types=1);

namespace App\Places\Application;

interface AdminPlaceGateway
{
    /** @return list<array<string, mixed>> */
    public function list(): array;

    /** @param array<string, scalar|null> $data */
    public function createDraft(array $data): string;

    /** @return list<string> */
    public function publicationProblems(string $id): array;

    public function changeStatus(string $id, string $status, ?\DateTimeImmutable $publishedAt): void;
}
