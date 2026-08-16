<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Support;

use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder;
use Rick\Laravel\Tests\Support\Agents\ExtractClaimFacts;
use Rick\Laravel\Tests\Support\Agents\FlagRisk;
use Rick\Laravel\Tests\Support\WorkflowSteps\LoadClaim;
use Rick\Laravel\Tests\Support\WorkflowSteps\StoreDecision;
use Rick\Laravel\Workflow;

final class ClaimDecisionWorkflow extends Workflow
{
    public function name(): string
    {
        return 'claim-decision';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function build(WorkflowBuilder $workflow): WorkflowBuilder
    {
        return $workflow
            ->budget(maxCostUsd: '0.25', requireKnownPricing: false)
            ->step(LoadClaim::class, as: 'load-claim', label: 'Loading claim')
            ->agent(ExtractClaimFacts::class, as: 'facts', label: 'Extracting claim facts')
            ->agent(FlagRisk::class, as: 'risk', label: 'Flagging risk')
            ->awaitHuman('approve', schema: ['approved' => ['required', 'boolean']])
            ->step(StoreDecision::class, as: 'store-decision', label: 'Storing decision')
            ->output('decision');
    }
}
