<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\TransactionManager;
use Doctrine\DBAL\Connection;

final readonly class DbalTransactionManager implements TransactionManager
{
    public function __construct(private Connection $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (Connection $_connection): mixed => $operation());
    }
}
