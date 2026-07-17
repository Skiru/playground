<?php

declare(strict_types=1);

namespace App\Personalization\Domain;

use Symfony\Component\Uid\Uuid;

interface PublishedPlaceLookup
{
    public function existsAndPublished(Uuid $placeId): bool;
}
