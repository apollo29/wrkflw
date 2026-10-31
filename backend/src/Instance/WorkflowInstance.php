<?php

declare(strict_types=1);

namespace WorkflowEngine\Instance;

/**
 * Ein Durchlauf einer Definition. Steht immer auf genau einem Schritt (5.4) und trägt
 * seinen eigenen Kontext. Bewusst veränderlich: die Engine schaltet ihn weiter, die
 * Ablage speichert ihn.
 */
final class WorkflowInstance
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $id,
        public readonly string $definitionId,
        public readonly int $definitionVersion,
        public string $currentStep,
        public InstanceStatus $status,
        public array $context = [],
        public ?\DateTimeImmutable $wakeAt = null,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public ?string $lastError = null,
        public int $attempts = 0,
    ) {
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function mergeContext(array $data): void
    {
        $this->context = array_replace($this->context, $data);
    }

    /**
     * Der Kontext ohne engine-interne Schlüssel.
     *
     * @return array<string, mixed>
     */
    public function publicContext(): array
    {
        return ContextKeys::stripInternal($this->context);
    }

    /** Die Instanz-ID des wartenden Eltern-Ablaufs, falls dieser Durchlauf ein Kind ist. */
    public function parentId(): ?string
    {
        $link = $this->context[ContextKeys::PARENT_LINK] ?? null;
        $id = is_array($link) ? ($link['instanceId'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Der Kind-Durchlauf, auf den dieser Durchlauf gerade wartet. */
    public function awaitedChildId(): ?string
    {
        $id = $this->context[ContextKeys::AWAIT_WORKFLOW] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
