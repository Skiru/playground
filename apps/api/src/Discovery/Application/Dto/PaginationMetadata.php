<?php

declare(strict_types=1);

namespace App\Discovery\Application\Dto;

final readonly class PaginationMetadata implements \JsonSerializable
{
    public function __construct(
        public int $page,
        public int $pageSize,
        public int $totalItems,
        public int $totalPages,
    ) {
    }

    /** @return array{page: int, pageSize: int, totalItems: int, totalPages: int} */
    public function jsonSerialize(): array
    {
        return ['page' => $this->page, 'pageSize' => $this->pageSize, 'totalItems' => $this->totalItems, 'totalPages' => $this->totalPages];
    }
}
