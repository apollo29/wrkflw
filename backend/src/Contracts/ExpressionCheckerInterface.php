<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Exception\ExpressionException;

/** Prüft einen Ausdruck auf Gültigkeit, ohne ihn auszuwerten. */
interface ExpressionCheckerInterface
{
    /**
     * @throws ExpressionException wenn der Ausdruck ungültig ist
     */
    public function check(string $expression): void;
}
