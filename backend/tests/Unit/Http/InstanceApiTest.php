<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Tests\Support\ApiDefinitions;
use WorkflowEngine\Tests\Support\ApiHarness;

/** Durchläufe über die API (F-50 bis F-56, Reiter «Instanzen»). */
final class InstanceApiTest extends TestCase
{
    private ApiHarness $api;

    protected function setUp(): void
    {
        $this->api = new ApiHarness();
        $this->api->h->define(ApiDefinitions::antrag());
    }

    private function start(string $antragId = 'A-1'): string
    {
        $response = $this->api->send('POST', '/workflows/antrag/instances', ['context' => ['antragId' => $antragId], 'subjectType' => 'antrag', 'subjectId' => $antragId]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return ApiHarness::str($response, 'id');
    }

    public function testStartAndShowWithoutInternalKeys(): void
    {
        $id = $this->start();

        $show = $this->api->send('GET', "/instances/{$id}");
        self::assertSame(200, $show->getStatusCode());
        self::assertSame('waiting_event', ApiHarness::at($show, 'status'));
        self::assertSame('erfassen', ApiHarness::at($show, 'currentStep'));
        self::assertSame(['antragId' => 'A-1'], ApiHarness::at($show, 'context'));
        self::assertSame('antrag', ApiHarness::at($show, 'subjectType'));
    }

    public function testMissingInputIs422WithTheNames(): void
    {
        $response = $this->api->send('POST', '/workflows/antrag/instances', ['context' => []]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('missing_input', ApiHarness::at($response, 'error.code'));
        self::assertSame(['antragId'], ApiHarness::at($response, 'error.details.missing'));
    }

    public function testUnknownWorkflowOrInstanceIs404(): void
    {
        self::assertSame(404, $this->api->send('POST', '/workflows/gibts-nicht/instances', [])->getStatusCode());
        foreach (['GET /instances/x', 'GET /instances/x/current-step', 'GET /instances/x/history', 'POST /instances/x/back', 'POST /instances/x/cancel'] as $route) {
            [$method, $path] = explode(' ', $route);
            $response = $this->api->send($method, $path);
            self::assertSame(404, $response->getStatusCode(), $route);
            self::assertSame('not_found', ApiHarness::at($response, 'error.code'), $route);
        }
        self::assertSame(404, $this->api->send('POST', '/instances/x/events', ['event' => 'weiter'])->getStatusCode());
    }

    public function testCurrentStepOffersEventsWithLabelsAndBack(): void
    {
        $id = $this->start();
        $step = $this->api->send('GET', "/instances/{$id}/current-step");
        self::assertSame('erfassen', ApiHarness::at($step, 'step'));
        self::assertSame('interactive', ApiHarness::at($step, 'type'));
        self::assertSame([['event' => 'weiter', 'label' => 'Weiter']], ApiHarness::at($step, 'events'));
        self::assertSame('Angaben', ApiHarness::at($step, 'ui.title'));
        self::assertFalse(ApiHarness::at($step, 'canGoBack'));

        $this->api->send('POST', "/instances/{$id}/events", ['event' => 'weiter', 'payload' => ['betrag' => 300]]);
        $step = $this->api->send('GET', "/instances/{$id}/current-step");
        self::assertSame([['event' => 'freigeben', 'label' => 'Freigeben'], ['event' => 'ablehnen', 'label' => 'ablehnen']], ApiHarness::at($step, 'events'));
        self::assertTrue(ApiHarness::at($step, 'canGoBack'));

        $back = $this->api->send('POST', "/instances/{$id}/back");
        self::assertSame(200, $back->getStatusCode());
        self::assertSame('erfassen', ApiHarness::at($back, 'currentStep'));
    }

    public function testEventNeedsAName(): void
    {
        $id = $this->start();
        $response = $this->api->send('POST', "/instances/{$id}/events", ['payload' => []]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_error', ApiHarness::at($response, 'error.code'));
        self::assertSame(['event'], array_keys((array) ApiHarness::at($response, 'error.details.fields')));
    }

    public function testDoubleClickWithTheSameIdempotencyKeyIsANoop(): void
    {
        $id = $this->start();
        $headers = ['Idempotency-Key' => 'klick-1'];
        self::assertSame(200, $this->api->send('POST', "/instances/{$id}/events", ['event' => 'weiter'], 'admin', $headers)->getStatusCode());
        $second = $this->api->send('POST', "/instances/{$id}/events", ['event' => 'weiter'], 'admin', $headers);

        self::assertSame(200, $second->getStatusCode());
        self::assertSame('pruefen', ApiHarness::at($second, 'currentStep'));
    }

    public function testUnknownEventStaysAndBackWithoutTargetIs409(): void
    {
        $id = $this->start();

        $wrong = $this->api->send('POST', "/instances/{$id}/events", ['event' => 'freigeben']);
        self::assertSame(200, $wrong->getStatusCode());
        self::assertSame('erfassen', ApiHarness::at($wrong, 'currentStep'));
        $back = $this->api->send('POST', "/instances/{$id}/back");
        self::assertSame(409, $back->getStatusCode());
        self::assertSame('invalid_state', ApiHarness::at($back, 'error.code'));
    }

    public function testCancelThenNoMoreEvents(): void
    {
        $id = $this->start();

        $cancel = $this->api->send('POST', "/instances/{$id}/cancel");
        self::assertSame(200, $cancel->getStatusCode());
        self::assertSame('cancelled', ApiHarness::at($cancel, 'status'));
        self::assertSame(409, $this->api->send('POST', "/instances/{$id}/cancel")->getStatusCode());
        self::assertSame(409, $this->api->send('POST', "/instances/{$id}/events", ['event' => 'weiter'])->getStatusCode());
    }

    public function testHistoryHasKindsAndTimes(): void
    {
        $id = $this->start();
        $this->api->send('POST', "/instances/{$id}/events", ['event' => 'weiter', 'payload' => ['betrag' => 300]]);

        $history = $this->api->send('GET', "/instances/{$id}/history");
        self::assertSame($id, ApiHarness::at($history, 'instanceId'));
        self::assertContains('event', ApiHarness::column($history, 'history', 'kind'));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', ApiHarness::str($history, 'history.0.at'));
        self::assertStringNotContainsString('300', (string) $history->getBody(), 'Verlauf nur mit Feldnamen');
    }

    public function testListWithFiltersAndCounts(): void
    {
        $a = $this->start('A-1');
        $this->start('A-2');
        $this->api->send('POST', "/instances/{$a}/cancel");

        $all = $this->api->send('GET', '/instances');
        self::assertCount(2, (array) ApiHarness::at($all, 'instances'));
        self::assertSame(1, ApiHarness::at($all, 'counts.cancelled'));
        self::assertSame(1, ApiHarness::at($all, 'counts.waiting_event'));
        self::assertSame('Antrag prüfen', ApiHarness::at($all, 'instances.0.definitionName'));

        self::assertSame([$a], ApiHarness::column($this->api->send('GET', '/instances?status=cancelled'), 'instances', 'id'));
        self::assertSame(['A-2'], ApiHarness::column($this->api->send('GET', '/instances?subjectId=A-2'), 'instances', 'subjectId'));
        self::assertCount(1, (array) ApiHarness::at($this->api->send('GET', '/instances?limit=1'), 'instances'));
        self::assertSame(422, $this->api->send('GET', '/instances?status=kaputt')->getStatusCode());
        self::assertSame(422, $this->api->send('GET', '/instances?limit=abc')->getStatusCode());
    }
}
