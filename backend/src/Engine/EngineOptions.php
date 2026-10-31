<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

/** Einstellungen der Engine mit den Vorgaben aus den Anforderungen. */
final readonly class EngineOptions
{
    /**
     * @param int $maxAttempts           Versuche einer Aktion, bevor der Durchlauf fehlschlägt (Q-01)
     * @param int $baseRetryDelaySeconds Abstand nach dem ersten Fehlschlag, danach verdoppelt
     * @param int $stepLimit             höchstens so viele automatische Schritte am Stück (Q-05)
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseRetryDelaySeconds = 60,
        public int $stepLimit = 1000,
        public EventPayloadPolicy $payloadPolicy = EventPayloadPolicy::Enforce,
    ) {
        if ($maxAttempts < 1 || $baseRetryDelaySeconds < 0 || $stepLimit < 1) {
            throw new \InvalidArgumentException('Ungültige Engine-Einstellungen: Versuche und Schritt-Limit müssen mindestens 1 sein, der Abstand mindestens 0.');
        }
    }
}
