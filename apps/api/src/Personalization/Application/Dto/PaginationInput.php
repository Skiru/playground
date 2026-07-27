<?php

declare(strict_types=1);

namespace App\Personalization\Application\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class PaginationInput
{
    public int $page;
    public int $pageSize;

    public function __construct(int $page, int $pageSize)
    {
        if ($page < 1) {
            throw new BadRequestHttpException('Page must be at least 1.');
        }
        if ($pageSize < 1 || $pageSize > 50) {
            throw new BadRequestHttpException('Page size must be between 1 and 50.');
        }
        $this->page = $page;
        $this->pageSize = $pageSize;
    }

    public static function fromRequest(Request $request): self
    {
        $page = $request->query->get('page');
        $pageSize = $request->query->get('pageSize');

        if (null !== $page && (!is_numeric($page) || (int) $page < 1)) {
            throw new BadRequestHttpException('Invalid page parameter.');
        }
        if (null !== $pageSize && (!is_numeric($pageSize) || (int) $pageSize < 1 || (int) $pageSize > 50)) {
            throw new BadRequestHttpException('Invalid pageSize parameter.');
        }

        return new self(
            null !== $page ? (int) $page : 1,
            null !== $pageSize ? (int) $pageSize : 20
        );
    }
}
