<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final readonly class DiscoveryOperationLock
{
    public function __construct(private LockFactory $factory, private DiscoveryLockKey $keys)
    {
    }

    public function operation(string $source, string $release, string $areaId): LockInterface
    {
        return $this->factory->createLock($this->keys->operation($source, $release, $areaId), 300, false);
    }

    public function releaseCheck(string $source): LockInterface
    {
        return $this->factory->createLock($this->keys->releaseCheck($source), 60, false);
    }
}
