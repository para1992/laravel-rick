<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Support\WorkflowSteps;

use Rick\Laravel\WorkflowState;

final class LoadClaim
{
    public function __invoke(WorkflowState $state): void
    {
        $state->put('claim', [
            'id' => $state->input('claim_id'),
            'body' => 'The claimant reports a rear-end collision on the highway.',
        ]);
    }
}
