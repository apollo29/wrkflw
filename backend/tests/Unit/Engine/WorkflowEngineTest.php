<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

#[CoversClass(WorkflowEngine::class)]
#[CoversClass(ActionRegistry::class)]
final class WorkflowEngineTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    private ActionRegistry $actions;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->onboarding()));

        $this->actions = new ActionRegistry();
        $this->actions->register('send_email', $this->noopAction());

        $this->engine = new WorkflowEngine($this->repo, $this->actions, new SymfonyExpressionEvaluator());
    }

    public function testHappyPathReachesCompleted(): void
    {
        $instance = $this->engine->start('onboarding', ['name' => 'Mara', 'email' => 'm@x.de', 'plan' => 'enterprise']);

        // Haelt zunaechst am interaktiven Schritt.
        self::assertSame(WorkflowInstance::WAITING_EVENT, $instance->status);
        self::assertSame('await_profile', $instance->currentStep);

        $this->engine->handleEvent($instance->id, 'submit', ['acceptedTerms' => true]);

        self::assertSame(WorkflowInstance::COMPLETED, $instance->status);
        self::assertSame('done', $instance->currentStep);
        self::assertContains('complete', $this->repo->historyKinds());
    }

    public function testBranchingEnterpriseVersusStandard(): void
    {
        $enterprise = $this->engine->start('onboarding', ['plan' => 'enterprise', 'email' => 'e@x.de']);
        $this->engine->handleEvent($enterprise->id, 'submit', ['acceptedTerms' => true]);
        self::assertSame(WorkflowInstance::COMPLETED, $enterprise->status);
        self::assertSame('done', $enterprise->currentStep);

        $standard = $this->engine->start('onboarding', ['plan' => 'pro', 'email' => 'p@x.de']);
        $this->engine->handleEvent($standard->id, 'submit', ['acceptedTerms' => true]);
        self::assertSame(WorkflowInstance::WAITING_TIMER, $standard->status);
        self::assertSame('wait_3_days', $standard->currentStep);
    }

    public function testInteractiveStepWaitsThenSubmitAdvances(): void
    {
        $instance = $this->engine->start('onboarding', ['plan' => 'pro', 'email' => 'p@x.de']);
        self::assertSame('await_profile', $instance->currentStep);
        self::assertSame(WorkflowInstance::WAITING_EVENT, $instance->status);

        $this->engine->handleEvent($instance->id, 'submit', ['acceptedTerms' => true]);

        self::assertNotSame('await_profile', $instance->currentStep);
    }

    public function testConditionNotMetStaysInStep(): void
    {
        $instance = $this->engine->start('onboarding', ['plan' => 'pro', 'email' => 'p@x.de']);

        // AGB nicht akzeptiert -> Selbstuebergang zurueck auf await_profile.
        $this->engine->handleEvent($instance->id, 'submit', ['acceptedTerms' => false]);

        self::assertSame('await_profile', $instance->currentStep);
        self::assertSame(WorkflowInstance::WAITING_EVENT, $instance->status);
    }

    public function testEventNotMatchingAnyTransitionDoesNotMove(): void
    {
        $instance = $this->engine->start('onboarding', ['plan' => 'pro', 'email' => 'p@x.de']);

        $this->engine->handleEvent($instance->id, 'unknown_event', []);

        self::assertSame('await_profile', $instance->currentStep);
        self::assertSame(WorkflowInstance::WAITING_EVENT, $instance->status);
    }

    public function testTimerSetsWakeAtAndResumesWhenDue(): void
    {
        $instance = $this->engine->start('onboarding', ['plan' => 'pro', 'email' => 'p@x.de']);
        $this->engine->handleEvent($instance->id, 'submit', ['acceptedTerms' => true]);

        self::assertSame(WorkflowInstance::WAITING_TIMER, $instance->status);
        self::assertNotNull($instance->wakeAt);
        self::assertGreaterThan(new \DateTimeImmutable(), $instance->wakeAt);

        // Faelligkeit simulieren und weiterlaufen lassen.
        $instance->wakeAt = (new \DateTimeImmutable())->modify('-1 minute');
        $this->engine->advance($instance);

        self::assertSame(WorkflowInstance::COMPLETED, $instance->status);
        self::assertSame('done', $instance->currentStep);
    }

    public function testFailingActionSetsFailedStatus(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'boom-flow',
            'startStep' => 'go',
            'steps' => [
                'go' => ['type' => 'automatic', 'action' => 'boom', 'transitions' => [['to' => 'done']]],
                'done' => ['type' => 'automatic'],
            ],
        ]));
        $this->actions->register('boom', $this->throwingAction('kaboom'));

        // maxAttempts=1 -> kein Retry, sofortiges Fehlschlagen.
        $engine = new WorkflowEngine($this->repo, $this->actions, new SymfonyExpressionEvaluator(), maxAttempts: 1);
        $instance = $engine->start('boom-flow');

        self::assertSame(WorkflowInstance::FAILED, $instance->status);
        self::assertNotNull($instance->lastError);
        self::assertStringContainsString('kaboom', $instance->lastError);
        self::assertContains('error', $this->repo->historyKinds());
    }

    public function testActionResultIsMergedIntoContext(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'enrich-flow',
            'startStep' => 'enrich',
            'steps' => [
                'enrich' => ['type' => 'automatic', 'action' => 'enrich', 'transitions' => [['to' => 'done']]],
                'done' => ['type' => 'automatic'],
            ],
        ]));
        $this->actions->register('enrich', $this->resultAction(['score' => 5]));

        $instance = $this->engine->start('enrich-flow', ['name' => 'x']);

        self::assertSame(WorkflowInstance::COMPLETED, $instance->status);
        self::assertSame(5, $instance->context['score']);
    }

    public function testHandleEventUnknownInstanceThrows(): void
    {
        $this->expectException(WorkflowException::class);
        $this->engine->handleEvent('does-not-exist', 'submit');
    }

    public function testHandleEventOnFinishedInstanceThrows(): void
    {
        $enterprise = $this->engine->start('onboarding', ['plan' => 'enterprise', 'email' => 'e@x.de']);
        $this->engine->handleEvent($enterprise->id, 'submit', ['acceptedTerms' => true]);
        self::assertTrue($enterprise->isFinished());

        $this->expectException(WorkflowException::class);
        $this->engine->handleEvent($enterprise->id, 'submit');
    }

    public function testLoopGuardFailsOnNonTerminatingDefinition(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'loop-flow',
            'startStep' => 'a',
            'steps' => [
                'a' => ['type' => 'automatic', 'transitions' => [['to' => 'a']]],
            ],
        ]));

        $instance = $this->engine->start('loop-flow');

        self::assertSame(WorkflowInstance::FAILED, $instance->status);
        self::assertNotNull($instance->lastError);
    }

    // ---------------------------------------------------------------- Helpers

    /** @return array<string,mixed> */
    private function onboarding(): array
    {
        $json = file_get_contents(dirname(__DIR__, 3) . '/examples/onboarding.json');
        self::assertIsString($json);
        /** @var array<string,mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    private function noopAction(): ActionInterface
    {
        return new class () implements ActionInterface {
            public function execute(WorkflowInstance $instance, Step $step): array
            {
                return [];
            }
        };
    }

    private function throwingAction(string $message): ActionInterface
    {
        return new class ($message) implements ActionInterface {
            public function __construct(private readonly string $message)
            {
            }

            public function execute(WorkflowInstance $instance, Step $step): array
            {
                throw new \RuntimeException($this->message);
            }
        };
    }

    /**
     * @param array<string,mixed> $result
     */
    private function resultAction(array $result): ActionInterface
    {
        return new class ($result) implements ActionInterface {
            /** @param array<string,mixed> $result */
            public function __construct(private readonly array $result)
            {
            }

            public function execute(WorkflowInstance $instance, Step $step): array
            {
                return $this->result;
            }
        };
    }
}
