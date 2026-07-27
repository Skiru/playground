<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use Symfony\Component\HttpFoundation\Request;

interface CsrfValidator
{
    public function validate(Request $request): void;
}
