<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\Candidate;
use Rick\Laravel\Domain\Run\CandidateDecision;
use Rick\Laravel\Domain\Run\ContextDocument;
use Rick\Laravel\Domain\Run\PendingInput;
use Rick\Laravel\Domain\Run\PendingReview;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\ResourceBudget;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class PublicReadDtoTest extends TestCase
{
    public function test_public_read_transport_matches_the_version_one_golden_fixture(): void
    {
        $stepId = StepId::fromString('002_generate');
        $candidateId = CandidateId::fromString('candidate-1');
        $artifactType = ArtifactType::fromString('draft');
        $candidate = new Candidate(
            $candidateId,
            $stepId,
            $artifactType,
            'Candidate 1',
            'Summary',
            ['rank' => 1],
            'Draft',
            'seed-1',
            'independent candidate generation',
            ['output_key' => 'draft'],
        );
        $context = new ContextDocument('brief', 'Brief', 8, 5, true);
        $decision = new CandidateDecision(
            $stepId,
            $candidateId,
            91.5,
            'Best candidate',
            'manual',
        );
        $artifact = new Artifact(
            'draft',
            $artifactType,
            'Draft',
            ['rank' => 1],
            ['source' => 'selected_candidate'],
            2,
        );
        $budget = new ResourceBudget(
            maxInputTokens: 100,
            maxOutputTokens: 50,
            maxTotalTokens: 150,
            maxCost: InvocationCost::fromUsd('0.25'),
            maxLatencyMilliseconds: 2500,
            maxDurationMilliseconds: 10000,
            defaultOutputReservationTokens: 64,
            requireCompleteMetrics: true,
            requireKnownPricing: false,
        );
        $snapshot = new WorkflowRunSnapshot(
            RunId::fromString('run-transport-1'),
            RunStatus::Completed,
            7,
            new RunInput(['subject' => 'Laravel']),
            'Write a draft',
            DefinitionOfDone::fromString('The draft is concise'),
            [$context],
            [],
            [$candidate],
            [$decision],
            ['002_generate' => ['state' => 'done']],
            null,
            'Draft',
            1,
            5,
            ['draft' => $artifact],
            $budget,
            new DateTimeImmutable('2026-08-01T12:34:56+00:00'),
        );
        $pendingReview = new PendingReview($stepId, [$candidate]);
        $pendingInput = new PendingInput(
            StepId::fromString('003_approval'),
            'approval',
            'Approve?',
            [
                'type' => 'object',
                'properties' => ['approved' => ['type' => 'boolean']],
                'required' => ['approved'],
            ],
        );

        $transport = [
            'snapshot' => $snapshot->toArray(),
            'pending_review' => $pendingReview->toArray(),
            'pending_input' => $pendingInput->toArray(),
            'candidate' => $candidate->toArray(),
            'artifact' => $artifact->toArray(),
            'context' => $context->toArray(),
            'decision' => $decision->toArray(),
            'resource_budget' => $budget->toArray(),
        ];
        $json = json_encode(
            $transport,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        self::assertJsonStringEqualsJsonFile(
            dirname(__DIR__, 2).'/Fixtures/public-read-dtos-v1.json',
            $json,
        );
        self::assertSame($snapshot->toArray(), $snapshot->jsonSerialize());
        self::assertSame($pendingReview->toArray(), $pendingReview->jsonSerialize());
        self::assertSame($pendingInput->toArray(), $pendingInput->jsonSerialize());
        self::assertSame($candidate->toArray(), $candidate->jsonSerialize());
        self::assertSame($artifact->toArray(), $artifact->jsonSerialize());
        self::assertSame($context->toArray(), $context->jsonSerialize());
        self::assertSame($decision->toArray(), $decision->jsonSerialize());
        self::assertSame($budget->toArray(), $budget->jsonSerialize());
    }
}
