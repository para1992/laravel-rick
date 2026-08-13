<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationAttemptId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Llm\ValueObject\TextResponsePolicy;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\CandidateSelection;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunObservation;
use Rick\Laravel\Domain\Run\RunPage;
use Rick\Laravel\Domain\Run\RunRecovery;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\RunSummary;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class PublicReadSerializationTest extends TestCase
{
    public function test_text_response_policies_expose_strict_and_partial_modes(): void
    {
        $final = TextResponsePolicy::finalArtifact(20);
        self::assertSame(20, $final->minimumCharacters);
        self::assertFalse($final->allowTruncated);
        self::assertContains('content safety:', $final->rejectedPrefixes);

        $partial = TextResponsePolicy::partial(3, ['refusal:']);
        self::assertSame(3, $partial->minimumCharacters);
        self::assertTrue($partial->allowTruncated);
        self::assertSame(['refusal:'], $partial->rejectedPrefixes);
    }

    public function test_text_response_policy_rejects_invalid_limits_and_prefixes(): void
    {
        foreach ([
            static fn () => new TextResponsePolicy(0),
            static fn () => new TextResponsePolicy(1, [' ']),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected invalid response policy.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_run_summary_page_recovery_and_observation_serialize_all_fields(): void
    {
        $time = new DateTimeImmutable('2026-08-08T10:00:00+00:00');
        $recovery = new RunRecovery(
            RunId::fromString('parent-run'),
            RunRecoveryAction::ForkFailedStep,
            StepId::fromString('step-1'),
        );
        $summary = new RunSummary(
            RunId::fromString('child-run'),
            RunStatus::Running,
            4,
            $time,
            $time,
            $recovery,
        );
        $page = new RunPage([$summary], 'next-cursor');
        $observation = new RunObservation(
            'event-1',
            'invocation.completed',
            5,
            $time,
            StepId::fromString('step-1'),
            InvocationId::fromString('invocation-1'),
            2,
            'rick.text',
            ['safe' => true],
            InvocationAttemptId::fromString('attempt-1'),
        );

        self::assertSame($recovery->toArray(), $recovery->jsonSerialize());
        self::assertSame('fork_failed_step', $recovery->toArray()['action']);
        self::assertSame($summary->toArray(), $summary->jsonSerialize());
        self::assertSame($page->toArray(), $page->jsonSerialize());
        self::assertSame('next-cursor', $page->toArray()['next_cursor']);
        self::assertSame($observation->toArray(), $observation->jsonSerialize());
        self::assertSame('attempt-1', $observation->toArray()['attempt_id']);

        $withoutRecovery = new RunSummary(
            RunId::fromString('plain-run'),
            RunStatus::Created,
            1,
            $time,
            $time,
        );
        self::assertArrayNotHasKey('recovery', $withoutRecovery->toArray());
    }

    public function test_candidate_selection_forwards_output_artifact_and_transport(): void
    {
        $artifact = new Artifact(
            'result',
            ArtifactType::fromString('result'),
            'Rendered result',
            [],
            [],
            1,
        );
        $snapshot = new WorkflowRunSnapshot(
            RunId::fromString('run-1'),
            RunStatus::Completed,
            3,
            new RunInput([]),
            'Task',
            DefinitionOfDone::automatic(),
            [],
            [],
            [],
            [],
            [],
            null,
            'Rendered result',
            0,
            1,
            ['result' => $artifact],
        );
        $selection = new CandidateSelection($snapshot, true);

        self::assertSame('Rendered result', $selection->output());
        self::assertSame($artifact, $selection->artifact('result'));
        self::assertSame($selection->toArray(), $selection->jsonSerialize());
        self::assertSame('run-1', $selection->toArray()['run_id']);
        self::assertTrue($selection->toArray()['continuation_queued']);
    }
}
