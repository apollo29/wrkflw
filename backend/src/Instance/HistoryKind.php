<?php

declare(strict_types=1);

namespace WorkflowEngine\Instance;

/** Die Arten von Verlaufseinträgen (F-90). Werte wie in 2.x. */
enum HistoryKind: string
{
    case Start = 'start';
    case WaitEvent = 'wait_event';
    case WaitTimer = 'wait_timer';
    case WaitSubWorkflow = 'wait_subworkflow';
    case SubWorkflowDone = 'subworkflow_done';
    case Action = 'action';
    case Transition = 'transition';
    case Event = 'event';
    case EventDuplicate = 'event_duplicate';
    case EventPayloadRejected = 'event_payload_rejected';
    case Retry = 'retry';
    case Error = 'error';
    case Complete = 'complete';
    case Back = 'back';
    case Cancelled = 'cancelled';
}
