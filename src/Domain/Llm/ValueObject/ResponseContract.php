<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Llm\ValueObject;

enum ResponseContract: string
{
    case Text = 'text';
    case Json = 'json';
    case Candidate = 'candidate';
    case MemoryCandidate = 'memory_candidate';
    case PlanCandidate = 'plan_candidate';
    case Judge = 'judge';
    case UnfoldUnits = 'unfold_units';
    case DefinitionOfDone = 'definition_of_done';
}
