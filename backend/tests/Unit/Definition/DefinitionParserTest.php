<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Definition\DefinitionParser;
use WorkflowEngine\Definition\StepType;
use WorkflowEngine\Exception\InvalidDefinitionException;

final class DefinitionParserTest extends TestCase
{
    public function testParsesTheExampleFlowIn2xFormat(): void
    {
        $def = DefinitionParser::fromJson(self::fixture(), version: 3);

        self::assertSame('antrag-pruefung', $def->id);
        self::assertSame(3, $def->version);
        self::assertSame('Antrag prüfen', $def->name);
        self::assertSame('daten_laden', $def->startStep);
        self::assertCount(6, $def->steps);
        self::assertSame(StepType::Interactive, $def->step('pruefen')->type);
        self::assertSame(StepType::Timer, $def->step('warten')->type);
        self::assertSame(86400, $def->step('warten')->delaySeconds);
        self::assertSame('submit', $def->step('pruefen')->transitions[0]->event);
        self::assertSame('true', $def->step('warten')->transitions[0]->when);
        self::assertSame(['antrag_id', 'antrag_betrag', 'antrag_mail'], array_map(
            static fn ($input): string => $input->name,
            $def->inputs,
        ));
        self::assertSame('A-1024', $def->inputs[0]->example);
    }

    public function testDuplicateStepNamesAreReportedInsteadOfSilentlyMerged(): void
    {
        $json = '{"id":"x","startStep":"a","steps":{"a":{"transitions":[{"to":"b"}]},'
            . '"b":{"type":"automatic"},"a":{"type":"interactive"}}}';

        try {
            DefinitionParser::fromJson($json);
            self::fail('Doppelter Schrittname nicht erkannt.');
        } catch (InvalidDefinitionException $e) {
            self::assertSame(["Schrittname 'a' kommt mehrfach vor."], $e->errors());
        }
    }

    public function testDuplicateKeysWithinAStepAreReported(): void
    {
        $json = '{"id":"x","startStep":"a","steps":{"a":{"type":"automatic","type":"timer"}}}';

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage("Schlüssel 'steps.a.type' kommt mehrfach vor.");
        DefinitionParser::fromJson($json);
    }

    public function testSameKeyInDifferentObjectsIsFine(): void
    {
        $json = '{"id":"x","startStep":"a","steps":{"a":{"transitions":[{"to":"b"}]},"b":{"transitions":[]}}}';

        self::assertCount(2, DefinitionParser::fromJson($json)->steps);
    }

    public function testEscapedQuotesInStringsDoNotConfuseTheKeyCheck(): void
    {
        $json = '{"id":"x","startStep":"a","steps":{"a":{"transitions":[{"to":"b","when":"context[\"a\"] == \"}\""}]},'
            . '"b":{}}}';

        self::assertSame('context["a"] == "}"', DefinitionParser::fromJson($json)->step('a')->transitions[0]->when);
    }

    public function testInvalidJsonIsADefinitionError(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('Kein gültiges JSON');
        DefinitionParser::fromJson('{"id":');
    }

    public function testUnknownStepTypeIsRejected(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage("Schritt 'a' hat einen unbekannten Typ");
        DefinitionParser::fromJson('{"id":"x","startStep":"a","steps":{"a":{"type":"manual"}}}');
    }

    public function testMissingIdOrStepsIsRejected(): void
    {
        foreach (['{"startStep":"a","steps":{"a":{}}}', '{"id":"x","startStep":"a","steps":{}}'] as $json) {
            try {
                DefinitionParser::fromJson($json);
                self::fail("Nicht abgelehnt: {$json}");
            } catch (InvalidDefinitionException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRoundTripKeepsTheStructure(): void
    {
        $def = DefinitionParser::fromJson(self::fixture());
        $again = DefinitionParser::fromArray($def->toArray());

        self::assertEquals($def, $again);
    }

    public static function fixture(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/antrag-pruefung.json');
    }
}
