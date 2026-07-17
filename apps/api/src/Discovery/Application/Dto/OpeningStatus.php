<?php

declare(strict_types=1);

namespace App\Discovery\Application\Dto;

final readonly class OpeningStatus implements \JsonSerializable
{
    public function __construct(
        public ?bool $isOpenNow,
    ) {
    }

    public function jsonSerialize(): ?bool
    {
        return $this->isOpenNow;
    }
}
