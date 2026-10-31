<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/** Woher eine Änderung kommt: Ablauf, Version, Durchlauf und Schritt (F-128). */
final readonly class ChangeOrigin
{
    public function __construct(
        public string $definitionId,
        public int $definitionVersion,
        public string $instanceId,
        public string $step,
    ) {
    }

    /** Kurzform für Protokolle der Host-App, etwa `workflow:antrag-pruefung@3#<id>/speichern`. */
    public function toString(): string
    {
        return "workflow:{$this->definitionId}@{$this->definitionVersion}#{$this->instanceId}/{$this->step}";
    }
}
