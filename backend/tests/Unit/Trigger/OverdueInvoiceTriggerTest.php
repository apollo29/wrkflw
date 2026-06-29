<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Trigger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Tests\Support\ArrayDataProvider;
use WorkflowEngine\Tests\Support\OverdueInvoiceTrigger;

#[CoversClass(OverdueInvoiceTrigger::class)]
final class OverdueInvoiceTriggerTest extends TestCase
{
    public function testPollReturnsJobsOnlyForOverdueInvoices(): void
    {
        $data = new ArrayDataProvider([
            'invoice' => [
                ['id' => '1', 'daysOverdue' => 20],
                ['id' => '2', 'daysOverdue' => 5],
                ['id' => '3', 'daysOverdue' => 30],
            ],
        ]);
        $trigger = new OverdueInvoiceTrigger($data, threshold: 14, definition: 'dunning');

        $jobs = $trigger->poll();

        self::assertCount(2, $jobs);
        self::assertSame('dunning', $jobs[0]['definition']);
        self::assertSame('invoice', $jobs[0]['subjectType'] ?? null);
        self::assertSame('1', $jobs[0]['subjectId'] ?? null);
        self::assertSame('3', $jobs[1]['subjectId'] ?? null);
        self::assertSame('1', ($jobs[0]['context'] ?? [])['invoiceId'] ?? null);
    }

    public function testPollReturnsNothingWhenNoneOverdue(): void
    {
        $data = new ArrayDataProvider(['invoice' => [['id' => '1', 'daysOverdue' => 1]]]);
        $trigger = new OverdueInvoiceTrigger($data, threshold: 14, definition: 'dunning');

        self::assertSame([], $trigger->poll());
    }
}
