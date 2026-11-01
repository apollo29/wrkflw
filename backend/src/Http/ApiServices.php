<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\DefinitionRepositoryInterface;
use WorkflowEngine\Contracts\InstanceQueryInterface;
use WorkflowEngine\Contracts\TemplateRepositoryInterface;
use WorkflowEngine\Contracts\UploadHandlerCatalogInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Model\DataCatalog;

/**
 * Was die API braucht, gebündelt. Die Host-App baut es einmal zusammen; ohne
 * Datenquellen-Teil fehlen die Routen `/data-sources/*`.
 */
final readonly class ApiServices
{
    public function __construct(
        public WorkflowEngine $engine,
        public WorkflowRepositoryInterface $workflows,
        public DefinitionRepositoryInterface $definitions,
        public InstanceQueryInterface $instances,
        public TemplateRepositoryInterface $templates,
        public DefinitionValidator $validator,
        public ActionRegistry $actions,
        public DataCatalog $catalog,
        public ?UploadHandlerCatalogInterface $uploadHandlers = null,
        public ?DataSources $dataSources = null,
    ) {
    }
}
