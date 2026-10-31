<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/** Maschinenlesbare Art eines Definitionsfehlers, für Editor und API. */
enum ValidationCode: string
{
    case StartStepMissing = 'start_step_missing';
    case TargetUnknown = 'target_unknown';
    case Unreachable = 'unreachable';
    case NoExit = 'no_exit';
    case InteractiveWithoutEvent = 'interactive_without_event';
    case NoEventlessExit = 'no_eventless_exit';
    case DelayInvalid = 'delay_invalid';
    case ExpressionInvalid = 'expression_invalid';
    case RuleViolation = 'rule_violation';
}
