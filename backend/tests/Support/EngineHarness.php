<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Action\SubWorkflowAction;
use WorkflowEngine\Definition\DefinitionParser;
use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\EngineOptions;
use WorkflowEngine\Engine\PlaceholderRenderer;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Baut eine Engine mit In-Memory-Fakes und eingefrorener Uhr zusammen.
 */
final class EngineHarness
{
    public readonly FrozenClock $clock;

    public readonly InMemoryWorkflowRepository $repo;

    public readonly ArrayMailer $mailer;

    public readonly InMemoryTemplateRepository $templates;

    public readonly ActionRegistry $actions;

    public readonly SymfonyExpressionEvaluator $expressions;

    public readonly WorkflowEngine $engine;

    public function __construct(EngineOptions $options = new EngineOptions())
    {
        $this->clock = new FrozenClock();
        $this->repo = new InMemoryWorkflowRepository($this->clock);
        $this->mailer = new ArrayMailer();
        $this->templates = new InMemoryTemplateRepository();
        $this->expressions = new SymfonyExpressionEvaluator($this->clock);
        $this->actions = new ActionRegistry();

        $renderer = new PlaceholderRenderer($this->clock);
        $this->engine = new WorkflowEngine($this->repo, $this->actions, $this->expressions, $this->clock, $options);

        $this->actions->register('send_email', new SendEmailAction($this->mailer, $this->templates, $renderer));
        $this->actions->register('start_workflow', new SubWorkflowAction(fn (): WorkflowEngine => $this->engine));
    }

    /**
     * Legt eine Definition an. Standardmässig wird sie geprüft, damit Tippfehler im
     * Test auffallen; Tests für fehlerhafte Abläufe schalten das ab.
     *
     * @param array<string, mixed> $definition
     */
    public function define(array $definition, int $version = 1, bool $validate = true): WorkflowDefinition
    {
        $def = DefinitionParser::fromArray($definition, $version);
        if ($validate) {
            (new DefinitionValidator($this->expressions))->validate($def);
        }
        $this->repo->addDefinition($def);

        return $def;
    }

    public function defineJson(string $json, int $version = 1): WorkflowDefinition
    {
        $def = DefinitionParser::fromJson($json, $version);
        (new DefinitionValidator($this->expressions))->validate($def);
        $this->repo->addDefinition($def);

        return $def;
    }

    public function reload(string $instanceId): WorkflowInstance
    {
        return $this->repo->findInstance($instanceId) ?? throw new \LogicException("Instanz {$instanceId} fehlt.");
    }
}
