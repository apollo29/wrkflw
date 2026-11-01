<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use WorkflowEngine\Contracts\AdminTriggerRepositoryInterface;
use WorkflowEngine\Contracts\AuditLogInterface;
use WorkflowEngine\Contracts\RestConnectionRepositoryInterface;
use WorkflowEngine\Contracts\RestModelRepositoryInterface;
use WorkflowEngine\Rest\DataSourceAdmin;

/** Verwaltung (schreibend) und Ablage (lesend) der Datenquellen für die API. */
final readonly class DataSources
{
    public function __construct(
        public DataSourceAdmin $admin,
        public RestConnectionRepositoryInterface $connections,
        public RestModelRepositoryInterface $models,
        public AdminTriggerRepositoryInterface $triggers,
        public AuditLogInterface $audit,
    ) {
    }
}
