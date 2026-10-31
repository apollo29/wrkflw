<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

enum TemplateType: string
{
    case Email = 'email';
    case Page = 'page';
}
