<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Rest\RestConnection;

/** Ablage der REST-Verbindungen. Zugangsdaten nur verschlüsselt. */
interface RestConnectionRepositoryInterface
{
    public function findConnection(string $id): ?RestConnection;

    /**
     * @return list<RestConnection>
     */
    public function allConnections(): array;

    public function saveConnection(RestConnection $connection): void;

    public function deleteConnection(string $id): void;
}
