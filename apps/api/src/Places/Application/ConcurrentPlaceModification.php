<?php

declare(strict_types=1);

namespace App\Places\Application;

final class ConcurrentPlaceModification extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This place was changed by another administrator. Reload it and retry.');
    }
}
