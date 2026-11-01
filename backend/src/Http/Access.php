<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use WorkflowEngine\Rest\Actor;

/** Ergebnis von {@see \WorkflowEngine\Contracts\AccessResolverInterface}: Person und Recht. */
final readonly class Access
{
    public const string ATTRIBUTE = 'wf.access';

    public function __construct(
        public Actor $actor,
        public bool $admin = false,
    ) {
    }
}
