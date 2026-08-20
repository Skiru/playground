<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        $env = $this->getEnvironment();
        if (\in_array($env, ['prod', 'production'], true)) {
            $devAuth = $_ENV['DEV_AUTH_ENABLED'] ?? $_SERVER['DEV_AUTH_ENABLED'] ?? 'false';
            if (\in_array(strtolower((string) $devAuth), ['1', 'true', 'yes', 'on'], true)) {
                throw new \LogicException('CRITICAL SECURITY CONFIGURATION FAILURE: DEV_AUTH_ENABLED cannot be enabled in production environment.');
            }
        }

        parent::boot();
    }
}
