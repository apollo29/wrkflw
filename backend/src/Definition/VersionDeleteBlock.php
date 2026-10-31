<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/** Warum eine Version nicht entfernt werden darf (F-13, E-66). */
enum VersionDeleteBlock: string
{
    case Latest = 'latest';
    case Referenced = 'referenced';
    case NotFound = 'not_found';

    public function message(): string
    {
        return match ($this) {
            self::Latest => 'Die neueste Version bleibt immer erhalten.',
            self::Referenced => 'Ein Durchlauf verweist noch darauf.',
            self::NotFound => 'Diese Version gibt es nicht.',
        };
    }
}
