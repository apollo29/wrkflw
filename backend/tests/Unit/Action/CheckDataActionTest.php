<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\CheckDataAction;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryDataProvider;

#[CoversClass(CheckDataAction::class)]
final class CheckDataActionTest extends TestCase
{
    /** @param array<string,mixed> $context */
    private function instance(array $context): WorkflowInstance
    {
        return new WorkflowInstance(
            id: 'i1',
            definitionId: 'flow',
            definitionVersion: 1,
            currentStep: 'check',
            status: WorkflowInstance::RUNNING,
            context: $context,
        );
    }

    /** @param array<string,mixed> $config */
    private function step(array $config): Step
    {
        return Step::fromArray('check', ['type' => 'automatic', 'action' => 'check_data', 'config' => $config]);
    }

    public function testReadsFieldIntoContextUnderAlias(): void
    {
        $data = new InMemoryDataProvider();
        $data->set('order', '42', ['id' => 42, 'status' => 'paid', 'total' => 99]);
        $action = new CheckDataAction($data);

        $result = $action->execute(
            $this->instance(['orderId' => 42]),
            $this->step(['entity' => 'order', 'id' => '{{orderId}}', 'field' => 'status', 'as' => 'orderStatus']),
        );

        self::assertSame('paid', $result['orderStatus']);
        self::assertTrue($result['orderStatusFound']);
    }

    public function testMissingRowYieldsNullAndNotFound(): void
    {
        $data = new InMemoryDataProvider();
        $action = new CheckDataAction($data);

        $result = $action->execute(
            $this->instance(['orderId' => 999]),
            $this->step(['entity' => 'order', 'id' => '{{orderId}}', 'field' => 'status', 'as' => 'orderStatus']),
        );

        self::assertNull($result['orderStatus']);
        self::assertFalse($result['orderStatusFound']);
    }

    public function testDefaultAliasWhenNotConfigured(): void
    {
        $data = new InMemoryDataProvider();
        $data->set('user', '7', ['id' => 7, 'vip' => true]);
        $action = new CheckDataAction($data);

        $result = $action->execute(
            $this->instance(['uid' => 7]),
            $this->step(['entity' => 'user', 'id' => '{{uid}}', 'field' => 'vip']),
        );

        self::assertTrue($result['checkedValue']);
        self::assertTrue($result['checkedValueFound']);
    }

    // --------------------------------------------------- mehrere Felder (2a)

    /**
     * `fields` liest mehrere Spalten auf einmal. Die Schluessel tragen den
     * Spaltennamen als Endung, damit zwei Datencheck-Schritte im selben
     * Workflow sich nicht gegenseitig ueberschreiben.
     */
    public function testReadsSeveralFieldsUnderPrefixedKeys(): void
    {
        $data = new InMemoryDataProvider();
        $data->set('trainer', '7', ['id' => 7, 'vorname' => 'Max', 'mail' => 'max@example.test']);
        $action = new CheckDataAction($data);

        $result = $action->execute(
            $this->instance(['tid' => 7]),
            $this->step(['entity' => 'trainer', 'id' => '{{tid}}', 'fields' => ['vorname', 'mail'], 'as' => 'p']),
        );

        self::assertSame('Max', $result['p_vorname']);
        self::assertSame('max@example.test', $result['p_mail']);
        self::assertTrue($result['pFound']);
    }

    /**
     * Die ganze Zeile gibt es bewusst nicht. Der Kontext ist auf der
     * oeffentlichen Seite sichtbar; was gelesen wird, soll in der Definition
     * dastehen — und nicht davon abhaengen, welche Spalten die Tabelle gerade
     * hat.
     */
    public function testStarIsNotAWildcard(): void
    {
        $data = new InMemoryDataProvider();
        $data->set('trainer', '7', ['id' => 7, 'vorname' => 'Max', 'ahv' => '756.1234']);
        $action = new CheckDataAction($data);

        $ausStern = $action->execute(
            $this->instance(['tid' => 7]),
            $this->step(['entity' => 'trainer', 'id' => '{{tid}}', 'fields' => '*', 'as' => 'p']),
        );
        $ausListe = $action->execute(
            $this->instance(['tid' => 7]),
            $this->step(['entity' => 'trainer', 'id' => '{{tid}}', 'fields' => ['*'], 'as' => 'p']),
        );

        foreach ([$ausStern, $ausListe] as $result) {
            self::assertSame(['p', 'pFound'], $this->schluessel($result));
            self::assertNull($result['p']);
            self::assertTrue($result['pFound']);
        }
    }

    /** Eine Spalte, die es nicht gibt, ist leer — kein Absturz, kein Auslassen. */
    public function testUnknownColumnBecomesNull(): void
    {
        $data = new InMemoryDataProvider();
        $data->set('trainer', '7', ['id' => 7, 'vorname' => 'Max']);
        $action = new CheckDataAction($data);

        $result = $action->execute(
            $this->instance(['tid' => 7]),
            $this->step(['entity' => 'trainer', 'id' => '{{tid}}', 'fields' => ['vorname', 'gibtsnicht'], 'as' => 'p']),
        );

        self::assertSame('Max', $result['p_vorname']);
        self::assertArrayHasKey('p_gibtsnicht', $result);
        self::assertNull($result['p_gibtsnicht']);
    }

    /**
     * Ohne Datensatz sind alle angeforderten Schluessel da und leer. Sie
     * muessen erscheinen: ein Anzeigefeld, dessen Schluessel fehlt, bliebe
     * sonst vom vorherigen Durchlauf stehen.
     */
    public function testMissingRowLeavesEveryFieldNull(): void
    {
        $action = new CheckDataAction(new InMemoryDataProvider());

        $result = $action->execute(
            $this->instance(['tid' => 999]),
            $this->step(['entity' => 'trainer', 'id' => '{{tid}}', 'fields' => ['vorname', 'mail'], 'as' => 'p']),
        );

        self::assertNull($result['p_vorname']);
        self::assertNull($result['p_mail']);
        self::assertFalse($result['pFound']);
    }

    /**
     * `field` und `fields` nebeneinander: beides gilt. `field` bleibt der
     * Pruefwert fuer die Uebergangsbedingung, `fields` sind die Werte zum
     * Anzeigen — die eine Aufgabe soll die andere nicht verdraengen.
     */
    public function testFieldAndFieldsCoexist(): void
    {
        $data = new InMemoryDataProvider();
        $data->set('trainer', '7', ['id' => 7, 'status' => 'aktiv', 'mail' => 'max@example.test']);
        $action = new CheckDataAction($data);

        $result = $action->execute(
            $this->instance(['tid' => 7]),
            $this->step([
                'entity' => 'trainer',
                'id' => '{{tid}}',
                'field' => 'status',
                'fields' => ['mail'],
                'as' => 'p',
            ]),
        );

        self::assertSame('aktiv', $result['p']);
        self::assertSame('max@example.test', $result['p_mail']);
    }

    /** Unbrauchbare Eintraege (Zahlen, Arrays, leere Namen) zaehlen nicht mit. */
    public function testUnusableEntriesAreIgnored(): void
    {
        $data = new InMemoryDataProvider();
        $data->set('trainer', '7', ['id' => 7, 'mail' => 'max@example.test']);
        $action = new CheckDataAction($data);

        $result = $action->execute(
            $this->instance(['tid' => 7]),
            $this->step([
                'entity' => 'trainer',
                'id' => '{{tid}}',
                'fields' => ['mail', '', '  ', 42, ['mail']],
                'as' => 'p',
            ]),
        );

        self::assertSame(['p', 'pFound', 'p_mail'], $this->schluessel($result));
        self::assertSame('max@example.test', $result['p_mail']);
    }

    /**
     * Die Schluessel des Ergebnisses, sortiert.
     *
     * Sortiert, weil die Reihenfolge nicht Teil der Zusage ist — geprueft
     * wird, dass GENAU diese Schluessel entstehen, nicht in welcher Folge.
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
