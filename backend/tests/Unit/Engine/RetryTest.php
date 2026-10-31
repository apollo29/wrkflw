<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Engine\RetryPolicy;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Tests\Support\EngineHarness;
use WorkflowEngine\Tests\Support\Models;

final class RetryTest extends TestCase
{
    private EngineHarness $h;

    protected function setUp(): void
    {
        $this->h = new EngineHarness();
        $this->h->define([
            'id' => 'mail', 'startStep' => 'senden',
            'steps' => [
                'senden' => [
                    'action' => 'send_email',
                    'config' => ['to' => 'a@example.org', 'subject' => 'Hallo', 'body' => 'Text'],
                    'transitions' => [['to' => 'fertig']],
                ],
                'fertig' => [],
            ],
        ]);
    }

    public function testBackoffDoublesAndStopsAtTheLimit(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelaySeconds: 60);

        self::assertSame(60, $policy->delayAfterAttempt(1));
        self::assertSame(120, $policy->delayAfterAttempt(2));
        self::assertNull($policy->delayAfterAttempt(3));
    }

    public function testAFailingActionIsRetriedLaterAndThenSucceeds(): void
    {
        $this->h->mailer->failNext(2);

        $instance = $this->h->engine->start('mail');
        self::assertSame(InstanceStatus::WaitingTimer, $instance->status);
        self::assertSame(1, $instance->attempts);
        self::assertSame('Mailversand: Zeitüberschreitung.', $instance->lastError);
        self::assertEquals($this->h->clock->now()->modify('+60 seconds'), $instance->wakeAt);

        // Zu früh geweckt: nichts passiert.
        $this->h->engine->advance($this->h->reload($instance->id));
        self::assertSame(1, $this->h->reload($instance->id)->attempts);

        $this->h->clock->advance('+60 seconds');
        $this->h->engine->advance($this->h->reload($instance->id));
        $instance = $this->h->reload($instance->id);
        self::assertSame(2, $instance->attempts);
        self::assertEquals($this->h->clock->now()->modify('+120 seconds'), $instance->wakeAt);

        $this->h->clock->advance('+120 seconds');
        $this->h->engine->advance($this->h->reload($instance->id));
        $instance = $this->h->reload($instance->id);
        self::assertSame(InstanceStatus::Completed, $instance->status);
        self::assertSame(0, $instance->attempts);
        self::assertCount(1, $this->h->mailer->sent);
        self::assertSame(['start', 'retry', 'retry', 'action', 'transition', 'complete'], $this->h->repo->historyKinds($instance->id));
    }

    public function testAfterTheLastAttemptTheInstanceFailsWithAReason(): void
    {
        $this->h->mailer->failNext(10);
        $id = $this->h->engine->start('mail')->id;

        foreach (['+60 seconds', '+120 seconds'] as $wait) {
            $this->h->clock->advance($wait);
            $this->h->engine->advance($this->h->reload($id));
        }

        $instance = $this->h->reload($id);
        self::assertSame(InstanceStatus::Failed, $instance->status);
        self::assertNull($instance->wakeAt);
        self::assertSame(
            "Aktion 'send_email' nach 3 Versuch(en) fehlgeschlagen: Mailversand: Zeitüberschreitung.",
            $instance->lastError,
        );
    }

    public function testPermanentFailuresAreNotRetried(): void
    {
        $this->h->addModel(Models::antrag());
        $this->h->storage->seed('antrag', [Models::antragRow('A-1')]);
        $this->h->define([
            'id' => 'falsch', 'startStep' => 'schreiben',
            'steps' => ['schreiben' => [
                'action' => 'write_data',
                'config' => ['entity' => 'antrag', 'id' => 'A-1', 'values' => ['geprueft_am' => 'irgendwann']],
            ]],
        ]);

        $instance = $this->h->engine->start('falsch');

        self::assertSame(InstanceStatus::Failed, $instance->status);
        self::assertSame(1, $instance->attempts);
        self::assertSame(
            "Aktion 'write_data' fehlgeschlagen: Der Wert für «Geprüft am» (geprueft_am) ist kein gültiges Datum.",
            $instance->lastError,
        );
        self::assertNotContains('retry', $this->h->repo->historyKinds($instance->id));
    }

    public function testAnUnknownActionFailsLikeATechnicalError(): void
    {
        $this->h->define([
            'id' => 'unbekannt', 'startStep' => 'a',
            'steps' => ['a' => ['action' => 'gibt_es_nicht']],
        ], validate: false);

        $instance = $this->h->engine->start('unbekannt');

        self::assertSame(InstanceStatus::WaitingTimer, $instance->status);
        self::assertStringContainsString("Keine Aktion für Schlüssel 'gibt_es_nicht'", (string) $instance->lastError);
    }
}
