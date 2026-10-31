<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Definition\DefinitionParser;
use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Definition\KnownActionRule;
use WorkflowEngine\Definition\ValidationError;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\DataModelStepRule;
use WorkflowEngine\Model\StaticDataModelSource;
use WorkflowEngine\Tests\Support\ArrayMailer;
use WorkflowEngine\Tests\Support\Models;

/** Prüfung von Aktionen und Datenmodellen beim Speichern (F-118). */
final class StepRulesTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private function errorsFor(string $action, array $config): array
    {
        $actions = new ActionRegistry();
        foreach (['check_data', 'write_data'] as $key) {
            $actions->register($key, new SendEmailAction(new ArrayMailer()));
        }
        $validator = new DefinitionValidator(rules: [
            new KnownActionRule($actions),
            new DataModelStepRule(new DataModelRegistry([new StaticDataModelSource([Models::antrag()])])),
        ]);
        $def = DefinitionParser::fromArray(['id' => 't', 'startStep' => 's', 'steps' => ['s' => ['action' => $action, 'config' => $config]]]);

        return array_map(
            static function (ValidationError $e): string {
                self::assertSame('s', $e->step);

                return $e->message;
            },
            $validator->check($def)->errors,
        );
    }

    public function testValidDataStepsPass(): void
    {
        self::assertSame([], $this->errorsFor('check_data', ['entity' => 'antrag', 'id' => '{{x}}', 'field' => 'status', 'fields' => ['betrag', 'mail']]));
        self::assertSame([], $this->errorsFor('write_data', ['entity' => 'antrag', 'id' => '{{x}}', 'values' => ['status' => 'a', 'geprueft_am' => ['wert' => '{{now}}', 'wenn' => 'true']]]));
    }

    public function testUnknownActionIsAnErrorAtTheStep(): void
    {
        self::assertSame(["Schritt 's': die Aktion 'gibt_es_nicht' ist nicht verfügbar."], $this->errorsFor('gibt_es_nicht', []));
    }

    public function testCheckDataProblems(): void
    {
        self::assertSame(["Schritt 's': es ist keine Tabelle gewählt."], $this->errorsFor('check_data', ['id' => '1']));
        self::assertSame(["Schritt 's': Datenmodell 'antraege' ist nicht bekannt."], $this->errorsFor('check_data', ['entity' => 'antraege']));
        self::assertSame(
            ["Schritt 's': Das Datenmodell 'antrag' hat kein Feld 'iban'.", "Schritt 's': «Alle Felder» gibt es nicht; bitte die Felder einzeln wählen."],
            $this->errorsFor('check_data', ['entity' => 'antrag', 'field' => 'iban', 'fields' => ['*']]),
        );
    }

    public function testWriteDataProblems(): void
    {
        self::assertSame(
            ["Schritt 's': Feld 'betrag' im Datenmodell 'antrag' ist nicht schreibbar.", "Schritt 's': Das Datenmodell 'antrag' hat kein Feld 'iban'."],
            $this->errorsFor('write_data', ['entity' => 'antrag', 'values' => ['betrag' => 1, 'iban' => 'x']]),
        );
        self::assertSame(
            ["Schritt 's': Das Datenmodell 'antrag' erlaubt kein Anlegen."],
            $this->errorsFor('write_data', ['entity' => 'antrag', 'values' => ['status' => 'x'], 'anlegen' => true]),
        );
        self::assertSame(
            ["Schritt 's': es sind keine Felder zum Schreiben gewählt."],
            $this->errorsFor('write_data', ['entity' => 'antrag', 'values' => []]),
        );
    }
}
