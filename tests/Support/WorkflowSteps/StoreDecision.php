<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Support\WorkflowSteps;

use Rick\Laravel\WorkflowState;

final class StoreDecision
{
    public function __invoke(WorkflowState $state): void
    {
        $state->put('decision', [
            'claim_id' => $state->input('claim_id'),
            'verdict' => $state->get('risk'),
        ]);
    }
}
