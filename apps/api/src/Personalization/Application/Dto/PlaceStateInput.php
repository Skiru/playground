<?php

declare(strict_types=1);

namespace App\Personalization\Application\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

final readonly class PlaceStateInput
{
    /** @var list<Uuid> */
    public array $placeIds;

    public function __construct(array $placeIds)
    {
        if (\count($placeIds) > 50) {
            throw new BadRequestHttpException('Exceeded maximum of 50 place IDs.');
        }
        $this->placeIds = $placeIds;
    }

    public static function fromRequest(Request $request): self
    {
        $placeIdsRaw = $request->query->all('placeIds') ?: $request->query->all('placeIds[]');

        $placeIds = [];
        foreach ($placeIdsRaw as $idStr) {
            if (!\is_string($idStr)) {
                throw new BadRequestHttpException('Invalid place ID type.');
            }
            if (!Uuid::isValid($idStr)) {
                throw new BadRequestHttpException('Invalid place ID format inside list.');
            }
            $placeIds[] = Uuid::fromString($idStr);
        }

        return new self($placeIds);
    }
}
