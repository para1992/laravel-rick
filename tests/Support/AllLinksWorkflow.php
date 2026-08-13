<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Support;

use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;
use Rick\Laravel\Rick;

final class AllLinksWorkflow
{
    public static function build(
        Rick $rick,
        string $maximumCostUsd,
        bool $requireKnownPricing = true,
    ): WorkflowDefinition {
        return $rick->workflow('live-all-built-in-links')
            ->budget(
                maxTotalTokens: 12000,
                maxCostUsd: $maximumCostUsd,
                defaultOutputReservationTokens: 320,
                requireCompleteMetrics: true,
                requireKnownPricing: $requireKnownPricing,
            )
            ->resolve(
                'Exercise every Rick workflow link with terse outputs; preserve literal markers.',
                DefinitionOfDone::automatic(),
            )
            ->context('source')
            ->context('evidence')
            ->context('collection')
            ->context('condition')
            ->waitForInput(
                'approval',
                'Approve the paid all-links smoke continuation?',
                ['type' => 'object', 'required' => ['approved']],
                'approval',
            )
            ->generate('draft', candidates: 1, outputKey: 'draft', reads: ['source'])
            ->manualJudge()
            ->operation(
                'rick.text',
                'operation_result',
                ['draft'],
                ['instruction' => 'Return one short line and preserve all literal markers.'],
            )
            ->parallel([
                new OperationCall(
                    'parallel_first',
                    'rick.text',
                    null,
                    ['operation_result'],
                    'parallel_one',
                    ['instruction' => 'Return a short first perspective.'],
                ),
                new OperationCall(
                    'parallel_second',
                    'rick.text',
                    null,
                    ['operation_result'],
                    'parallel_two',
                    ['instruction' => 'Return a short second perspective.'],
                ),
            ])
            ->map(
                'collection',
                'items',
                'rick.text',
                'mapped',
                ['instruction' => 'Echo the marker tersely.'],
                maxItems: 1,
            )
            ->join(['parallel_one', 'parallel_two', 'mapped'], 'joined', separator: "\n")
            ->branch('condition', '.', 'equals', 'yes', 'joined', 'operation_result', 'selected')
            ->qualityGate('selected', 'non_empty', output: 'checked')
            ->groundedVerify(
                'source',
                ['evidence'],
                output: 'verified',
                minimumQuoteCharacters: 7,
            )
            ->unfold('source', 'unit', candidates: 1, maxUnits: 1)
            ->outputGlue('unit')
            ->edit('strict')
            ->build();
    }

    /** @return list<string> */
    public static function coveredTypes(Rick $rick, WorkflowDefinition $workflow): array
    {
        $types = array_values(array_unique([
            'raw_prompt',
            ...array_map(
                static fn ($step): string => $step->type()->toString(),
                $rick->compile($workflow)->steps,
            ),
        ]));
        sort($types);

        return $types;
    }
}
