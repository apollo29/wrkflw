<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\WriteDataAction;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryDataWriter;

#[CoversClass(WriteDataAction::class)]
final class WriteDataActionTest extends TestCase
{
    /** @param array<string,mixed> $context */
    private function instance(array $context): WorkflowInstance
    {
        return new WorkflowInstance(
            id: 'i1',
            definitionId: 'flow',
            definitionVersion: 1,
            currentStep: 'write',
            status: WorkflowInstance::RUNNING,
            context: $context,
        );
    }

    /** @param array<string,mixed> $config */
    private function step(array $config): Step
    {
        return Step::fromArray('write', ['type' => 'automatic', 'action' => 'write_data', 'config' => $config]);
    }

    public function testWritesValuesWithPlaceholdersResolved(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['status' => 'in_ausbildung', 'gueltig_bis' => '']);
        $action = new WriteDataAction($writer);

        $result = $action->execute(
            $this->instance(['diplomId' => 7, 'bis' => '2030-06-30']),
            $this->step([
                'entity' => 'js_diplom',
                'id' => '{{diplomId}}',
                'values' => ['status' => 'anerkannt', 'gueltig_bis' => '{{bis}}'],
            ]),
        );

        self::assertSame(
            ['status' => 'anerkannt', 'gueltig_bis' => '2030-06-30'],
            $writer->row('js_diplom', '7'),
        );
        self::assertTrue($result['written']);
        self::assertSame(2, $result['writtenCount']);
    }

    public function testResultAliasIsConfigurable(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['status' => 'offen']);
        $action = new WriteDataAction($writer);

        $result = $action->execute(
            $this->instance([]),
            $this->step([
                'entity' => 'js_diplom',
                'id' => '7',
                'values' => ['status' => 'anerkannt'],
                'as' => 'gespeichert',
            ]),
        );

        self::assertSame(['gespeichert', 'gespeichertCount'], $this->schluessel($result));
        self::assertTrue($result['gespeichert']);
    }

    /**
     * Ein nicht vorhandener Datensatz ist eine ANTWORT, kein Absturz: der
     * Ablauf kann darauf verzweigen, statt auf `failed` zu fallen.
     */
    public function testMissingRowYieldsFalse(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['status' => 'offen']);
        $action = new WriteDataAction($writer);

        $result = $action->execute(
            $this->instance([]),
            $this->step(['entity' => 'js_diplom', 'id' => '999', 'values' => ['status' => 'anerkannt']]),
        );

        self::assertFalse($result['written']);
    }

    /**
     * Eine Spalte, die der Host nicht erlaubt, wird nicht geschrieben — und
     * das Ergebnis sagt es. `writtenCount` zaehlt, was GESENDET wurde; ob es
     * ankam, steht in `written`.
     */
    public function testHostRefusalIsReported(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('trainer', '7', ['iban' => 'CH00', 'tel_mobil' => ''], erlaubteSpalten: ['tel_mobil']);
        $action = new WriteDataAction($writer);

        $result = $action->execute(
            $this->instance([]),
            $this->step(['entity' => 'trainer', 'id' => '7', 'values' => ['iban' => 'CH99']]),
        );

        self::assertFalse($result['written']);
        self::assertSame(1, $result['writtenCount']);
        self::assertSame('CH00', $writer->wert('trainer', '7', 'iban'));
    }

    /**
     * Halb ausgefuellte Konfiguration schreibt NICHT. Sonst ginge eine
     * fehlende Tabelle oder ID als leerer String an den Host — und was der
     * daraus macht, soll hier nicht entschieden werden.
     *
     * @param array<string,mixed> $config
     */
    #[DataProvider('unvollstaendig')]
    public function testIncompleteConfigDoesNotWrite(array $config): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['status' => 'offen']);
        $action = new WriteDataAction($writer);

        $result = $action->execute($this->instance([]), $this->step($config));

        self::assertFalse($result['written']);
        self::assertSame(0, $result['writtenCount']);
        self::assertSame([], $writer->calls, 'Der Host darf gar nicht gefragt werden.');
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function unvollstaendig(): array
    {
        return [
            'ohne Tabelle' => [['id' => '7', 'values' => ['status' => 'x']]],
            'ohne ID' => [['entity' => 'js_diplom', 'values' => ['status' => 'x']]],
            'leere ID nach Ersetzung' => [
                ['entity' => 'js_diplom', 'id' => '{{fehlt}}', 'values' => ['status' => 'x']],
            ],
            'ohne Felder' => [['entity' => 'js_diplom', 'id' => '7']],
            'Felder kein Objekt' => [['entity' => 'js_diplom', 'id' => '7', 'values' => 'status']],
        ];
    }

    /**
     * Was keine Spalte sein kann, wird verworfen — bevor es als Spaltenname
     * beim Host ankommt. Die Definition ist im Editor bearbeitbar.
     */
    public function testUnusableColumnNamesAreDropped(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['status' => 'offen']);
        $action = new WriteDataAction($writer);

        $result = $action->execute(
            $this->instance([]),
            $this->step([
                'entity' => 'js_diplom',
                'id' => '7',
                'values' => [
                    'status' => 'anerkannt',
                    'status; DROP TABLE js_diplom' => 'x',
                    'nicht erlaubt' => 'x',
                    '' => 'x',
                    '1status' => 'x',
                ],
            ]),
        );

        self::assertSame(1, $result['writtenCount']);
        self::assertSame(['status' => 'anerkannt'], $writer->calls[0]['values']);
    }

    /**
     * Zahlen, Wahrheitswerte und `null` bleiben, was sie sind — ein Flag soll
     * nicht als Text `"1"` in einer Zahlenspalte landen. Arrays fallen heraus
     * statt als leerer String zu schreiben.
     */
    public function testNonStringValuesKeepTheirType(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['anzahl' => 0, 'aktiv' => false, 'notiz' => 'alt', 'liste' => '']);
        $action = new WriteDataAction($writer);

        $action->execute(
            $this->instance([]),
            $this->step([
                'entity' => 'js_diplom',
                'id' => '7',
                'values' => ['anzahl' => 3, 'aktiv' => true, 'notiz' => null, 'liste' => ['a']],
            ]),
        );

        self::assertSame(
            ['anzahl' => 3, 'aktiv' => true, 'notiz' => null, 'liste' => ''],
            $writer->row('js_diplom', '7'),
        );
    }

    /**
     * Die Herkunft nennt Definition UND Instanz. Steht in einer Zeile nur
     * «ein Workflow», ist im Nachhinein nicht mehr zuzuordnen, welcher.
     */
    public function testProvenanceNamesDefinitionAndInstance(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['status' => 'offen']);
        $action = new WriteDataAction($writer);

        $action->execute(
            $this->instance([]),
            $this->step(['entity' => 'js_diplom', 'id' => '7', 'values' => ['status' => 'anerkannt']]),
        );

        self::assertSame('workflow:flow#i1', $writer->calls[0]['herkunft']);
    }

    /**
     * Der Spaltenname kommt NIE aus dem Kontext. `{{...}}` im Schluessel
     * bleibt stehen, faellt damit durch die Namenspruefung und wird
     * verworfen — welche Spalten ein Schritt anfasst, steht in der Definition.
     */
    public function testColumnNameIsNotInterpolated(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['status' => 'offen']);
        $action = new WriteDataAction($writer);

        $result = $action->execute(
            $this->instance(['welche' => 'status']),
            $this->step(['entity' => 'js_diplom', 'id' => '7', 'values' => ['{{welche}}' => 'anerkannt']]),
        );

        self::assertFalse($result['written']);
        self::assertSame('offen', $writer->wert('js_diplom', '7', 'status'));
    }

    // ------------------------------------------- eingebaute Platzhalter (Uhr)

    /**
     * «Setze das Datum auf heute» ist der Normalfall. Ohne eingebaute Uhr
     * müsste der Wert von aussen in den Kontext kommen — bei einem Ablauf, der
     * durch einen Timer weiterläuft, wäre das der Zeitpunkt des STARTS.
     */
    public function testNowSchreibtDasHeutigeDatum(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['erworben_am' => '', 'bemerkung' => '']);
        $action = new WriteDataAction($writer);

        $action->execute(
            $this->instance([]),
            $this->step([
                'entity' => 'js_diplom',
                'id' => '7',
                'values' => ['erworben_am' => '{{now}}', 'bemerkung' => 'erfasst am {{now}}'],
            ]),
        );

        $heute = date('Y-m-d');
        self::assertSame($heute, $writer->wert('js_diplom', '7', 'erworben_am'));
        self::assertSame('erfasst am ' . $heute, $writer->wert('js_diplom', '7', 'bemerkung'));
    }

    /** ISO, weil die Datumsspalten so gefüllt sind und die Trigger so vergleichen. */
    public function testNowLiefertIsoUndKennDreiFormen(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['a' => '', 'b' => '', 'c' => '']);
        $action = new WriteDataAction($writer);

        $action->execute(
            $this->instance([]),
            $this->step([
                'entity' => 'js_diplom',
                'id' => '7',
                'values' => ['a' => '{{now}}', 'b' => '{{now.year}}', 'c' => '{{now.date}}'],
            ]),
        );

        self::assertSame(date('Y-m-d'), $writer->wert('js_diplom', '7', 'a'));
        self::assertSame(date('Y'), $writer->wert('js_diplom', '7', 'b'));
        self::assertSame(date('Y-m-d'), $writer->wert('js_diplom', '7', 'c'));
    }

    /**
     * `now` ist in den Übergangs-Bedingungen schon ein eingebauter Name. Dass
     * er hier dasselbe bedeutet, ist Absicht — ein Kontext-Schlüssel gleichen
     * Namens wird verdeckt und nicht etwa zufällig mal so, mal so aufgelöst.
     */
    public function testEingebautesNowSchlaegtDenKontext(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['erworben_am' => '']);
        $action = new WriteDataAction($writer);

        $action->execute(
            $this->instance(['now' => '1999-01-01']),
            $this->step(['entity' => 'js_diplom', 'id' => '7', 'values' => ['erworben_am' => '{{now}}']]),
        );

        self::assertSame(date('Y-m-d'), $writer->wert('js_diplom', '7', 'erworben_am'));
    }

    /**
     * Die drei Formen sind die Liste. Alles andere unter `now.` ist ein
     * gewöhnlicher Kontext-Schlüssel — und den gibt es nicht, also bleibt er
     * leer, wie jeder andere Tippfehler auch.
     */
    public function testUnbekanntesNowIstKeinEingebauterPlatzhalter(): void
    {
        $writer = new InMemoryDataWriter();
        $writer->seed('js_diplom', '7', ['erworben_am' => 'alt']);
        $action = new WriteDataAction($writer);

        $action->execute(
            $this->instance([]),
            $this->step([
                'entity' => 'js_diplom',
                'id' => '7',
                'values' => ['erworben_am' => '{{now.morgen}}'],
            ]),
        );

        self::assertSame('', $writer->wert('js_diplom', '7', 'erworben_am'));
    }

    /**
     * Die Schluessel des Ergebnisses, sortiert — geprueft wird, dass GENAU
     * diese entstehen, nicht in welcher Folge.
     *
     * @param array<string,mixed> $result
     *
     * @return list<string>
     */
    private function schluessel(array $result): array
    {
        $keys = array_keys($result);
        sort($keys);

        return $keys;
    }
}
