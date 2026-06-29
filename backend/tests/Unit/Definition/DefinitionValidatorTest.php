<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\InvalidDefinitionException;

#[CoversClass(DefinitionValidator::class)]
final class DefinitionValidatorTest extends TestCase
{
    private DefinitionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DefinitionValidator();
    }

    /** @param array<string,mixed> $steps */
    private function def(string $startStep, array $steps): WorkflowDefinition
    {
        return WorkflowDefinition::fromArray([
            'id' => 'test',
            'startStep' => $startStep,
            'steps' => $steps,
        ]);
    }

    public function testValidOnboardingPasses(): void
    {
        $path = dirname(__DIR__, 3) . '/examples/onboarding.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        /** @var array<string,mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $def = WorkflowDefinition::fromArray($data);

        // Wirft keine Exception -> Definition ist gueltig.
        $this->validator->validate($def);
    }

    public function testStartStepNotInStepsThrows(): void
    {
        $def = $this->def('nope', ['a' => ['transitions' => []]]);

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($def);
    }

    public function testUnknownTransitionTargetThrows(): void
    {
        $def = $this->def('a', [
            'a' => ['transitions' => [['to' => 'ghost']]],
        ]);

        try {
            $this->validator->validate($def);
            self::fail('Erwartete InvalidDefinitionException');
        } catch (InvalidDefinitionException $e) {
            self::assertNotEmpty($e->errors());
        }
    }

    public function testUnreachableStepThrows(): void
    {
        $def = $this->def('a', [
            'a' => ['transitions' => []],            // Endzustand
            'orphan' => ['transitions' => []],        // nie erreichbar
        ]);

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($def);
    }

    public function testCycleWithoutExitThrows(): void
    {
        // a -> b -> a, kein Endzustand erreichbar
        $def = $this->def('a', [
            'a' => ['transitions' => [['to' => 'b']]],
            'b' => ['transitions' => [['to' => 'a']]],
        ]);

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($def);
    }

    public function testEventTransitionsAreFollowedForReachability(): void
    {
        // erreichbar nur ueber eine Event-Transition -> darf nicht als unerreichbar gelten
        $def = $this->def('a', [
            'a' => ['type' => 'interactive', 'transitions' => [['event' => 'submit', 'to' => 'b']]],
            'b' => ['transitions' => []],
        ]);

        $this->validator->validate($def);
        $this->expectNotToPerformAssertions();
    }
}
