<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\WorkflowState;

final class WorkflowStateTest extends TestCase
{
    public function test_it_reads_initial_values_from_artifacts_preferring_payload(): void
    {
        $structured = new Artifact('contract', ArtifactType::fromString('application'), 'ignored', ['vendor' => 'acme']);
        $text = new Artifact('summary', ArtifactType::fromString('text'), 'hello world');

        $state = new WorkflowState(new RunInput([]), ['contract' => $structured, 'summary' => $text]);

        self::assertSame(['vendor' => 'acme'], $state->get('contract'));
        self::assertSame('hello world', $state->get('summary'));
    }

    public function test_nested_put_and_get_round_trip(): void
    {
        $state = new WorkflowState(new RunInput([]));

        $state->put('risk.score', 8)->put('a.b.c', 'deep');

        self::assertSame(8, $state->get('risk.score'));
        self::assertSame(['score' => 8], $state->get('risk'));
        self::assertSame('deep', $state->get('a.b.c'));
        self::assertSame(['risk' => ['score' => 8], 'a' => ['b' => ['c' => 'deep']]], $state->all());
    }

    public function test_put_replaces_a_non_array_intermediate_value(): void
    {
        $state = new WorkflowState(new RunInput([]));

        $state->put('note', 'hi');
        $state->put('note.sub', 'x');

        self::assertSame(['sub' => 'x'], $state->get('note'));
    }

    public function test_get_returns_the_default_for_missing_keys(): void
    {
        $state = new WorkflowState(new RunInput([]));
        $state->put('risk.score', 8);

        self::assertNull($state->get('missing'));
        self::assertSame('fallback', $state->get('missing', 'fallback'));
        self::assertSame(42, $state->get('risk.missing', 42));
        self::assertSame('fallback', $state->get('risk.score.missing', 'fallback'));
    }

    public function test_has_checks_top_level_and_nested_existence(): void
    {
        $state = new WorkflowState(new RunInput([]));
        $state->put('risk.score', 8);

        self::assertTrue($state->has('risk'));
        self::assertTrue($state->has('risk.score'));
        self::assertFalse($state->has('risk.other'));
        self::assertFalse($state->has('nope'));
    }

    public function test_forget_removes_top_level_and_nested_keys(): void
    {
        $state = new WorkflowState(new RunInput([]));
        $state->put('temporary', 'x');
        $state->put('risk.score', 8);

        $state->forget('temporary');
        $state->forget('risk.score');

        self::assertFalse($state->has('temporary'));
        self::assertNull($state->get('temporary'));
        self::assertTrue($state->has('risk'));
        self::assertSame([], $state->get('risk'));
    }

    public function test_input_reads_run_input_without_throwing(): void
    {
        $state = new WorkflowState(new RunInput(['product' => 'laptop']));

        self::assertSame('laptop', $state->input('product'));
        self::assertNull($state->input('missing'));
        self::assertSame('fallback', $state->input('missing', 'fallback'));
    }

    public function test_to_artifacts_returns_artifacts_only_for_put_keys(): void
    {
        $contract = new Artifact('contract', ArtifactType::fromString('application'), '{}', ['vendor' => 'acme']);
        $state = new WorkflowState(new RunInput([]), ['contract' => $contract]);

        $state->put('risk.score', 8);
        $state->put('note', 'hi');

        $artifacts = $state->toArtifacts();

        self::assertCount(2, $artifacts);

        [$risk, $note] = $artifacts;

        self::assertSame('risk', $risk->key);
        self::assertSame('application', $risk->type->toString());
        self::assertSame('{"score":8}', $risk->content);
        self::assertSame(['score' => 8], $risk->payload);
        self::assertSame([], $risk->metadata);
        self::assertSame(1, $risk->version);

        self::assertSame('note', $note->key);
        self::assertSame('application', $note->type->toString());
        self::assertSame('hi', $note->content);
        self::assertSame([], $note->payload);
    }

    public function test_to_artifacts_omits_keys_that_were_put_then_forgotten(): void
    {
        $state = new WorkflowState(new RunInput([]));

        $state->put('temporary', 'x');
        $state->forget('temporary');

        self::assertSame([], $state->toArtifacts());
    }

    public function test_construction_does_not_mutate_source_input_or_artifacts(): void
    {
        $input = new RunInput(['config' => ['level' => 'low']]);
        $artifact = new Artifact('risk', ArtifactType::fromString('application'), '{}', ['level' => 'low']);
        $state = new WorkflowState($input, ['risk' => $artifact]);

        $state->put('risk.score', 8);
        $state->put('config.extra', true);

        self::assertSame(['config' => ['level' => 'low']], $input->toArray());
        self::assertSame(['level' => 'low'], $artifact->payload);
    }

    public function test_artifact_returns_the_original_snapshot_object(): void
    {
        $artifact = new Artifact('risk', ArtifactType::fromString('application'), '{}', ['level' => 'low']);
        $state = new WorkflowState(new RunInput([]), ['risk' => $artifact]);

        $state->put('risk.score', 8);

        $original = $state->artifact('risk');

        self::assertSame($artifact, $original);
        self::assertSame(['level' => 'low'], $original->payload);
        self::assertNull($state->artifact('nope'));
    }

    public function test_run_id_is_exposed_when_provided(): void
    {
        $runId = RunId::fromString('run-123');

        self::assertNull((new WorkflowState(new RunInput([])))->runId());
        self::assertSame($runId, (new WorkflowState(new RunInput([]), [], $runId))->runId());
    }

    public function test_state_can_be_extended_with_typed_getters(): void
    {
        $state = new class(new RunInput([])) extends WorkflowState
        {
            public function riskScore(): int
            {
                $score = $this->get('risk.score');

                return is_int($score) ? $score : 0;
            }
        };

        $state->put('risk.score', 8);

        self::assertSame(8, $state->riskScore());
    }
}
