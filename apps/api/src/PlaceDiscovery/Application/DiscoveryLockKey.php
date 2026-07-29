<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application;

final readonly class DiscoveryLockKey
{
    public function operation(string $source, string $release, string $areaId): string
    {
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $source) || !preg_match('/^20\d{2}-\d{2}-\d{2}\.\d+$/', $release) || !\Symfony\Component\Uid\Uuid::isValid($areaId)) {
            throw new \InvalidArgumentException('Invalid discovery lock identity.');
        }

        return \sprintf('place-discovery:operation:%s:%s:%s', $source, $release, $areaId);
    }

    public function releaseCheck(string $source): string
    {
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $source)) {
            throw new \InvalidArgumentException('Invalid discovery source.');
        }

        return 'place-discovery:release-check:'.$source;
    }
}
