<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Memory\EntityMutation;
use Rick\Laravel\Domain\Memory\MemoryDelta;
use Rick\Laravel\Domain\Memory\UnitCard;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Metrics\ValueObject\TokenUsage;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Run\Candidate;
use Rick\Laravel\Domain\Run\CandidateDecision;
use Rick\Laravel\Domain\Run\DeliveryRecord;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\ValueObject\ResourceBudget;
use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class DomainValueContractsTest extends TestCase
{
    public function test_resource_budget_round_trips_every_limit_and_default(): void
    {
        $budget = new ResourceBudget(
            maxInputTokens: 100,
            maxOutputTokens: 200,
            maxTotalTokens: 250,
            maxCost: InvocationCost::fromUsd('1.25'),
            maxLatencyMilliseconds: 500,
            maxDurationMilliseconds: 1_000,
            defaultOutputReservationTokens: 64,
            requireCompleteMetrics: true,
            requireKnownPricing: false,
        );
        $expected = [
            'schema_version' => 1,
            'max_input_tokens' => 100,
            'max_output_tokens' => 200,
            'max_total_tokens' => 250,
            'max_cost_usd' => '1.25',
            'max_latency_milliseconds' => 500,
            'max_duration_milliseconds' => 1_000,
            'default_output_reservation_tokens' => 64,
            'require_complete_metrics' => true,
            'require_known_pricing' => false,
        ];

        self::assertFalse($budget->isUnbounded());
        self::assertSame($expected, $budget->toArray());
        self::assertSame($expected, $budget->jsonSerialize());
        self::assertSame($expected, ResourceBudget::fromArray($expected)->toArray());

        $unbounded = ResourceBudget::unbounded();
        self::assertTrue($unbounded->isUnbounded());
        self::assertSame([
            'schema_version' => 1,
            'max_input_tokens' => null,
            'max_output_tokens' => null,
            'max_total_tokens' => null,
            'max_cost_usd' => null,
            'max_latency_milliseconds' => null,
            'max_duration_milliseconds' => null,
            'default_output_reservation_tokens' => 2048,
            'require_complete_metrics' => false,
            'require_known_pricing' => true,
        ], ResourceBudget::fromArray([])->toArray());
    }

    #[DataProvider('invalidBudgets')]
    public function test_resource_budget_rejects_invalid_values(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidBudgets(): iterable
    {
        foreach (['maxInputTokens', 'maxOutputTokens', 'maxTotalTokens', 'maxLatencyMilliseconds', 'maxDurationMilliseconds'] as $field) {
            yield "zero {$field}" => [static fn () => new ResourceBudget(...[$field => 0])];
            yield "negative {$field}" => [static fn () => new ResourceBudget(...[$field => -1])];
        }
        yield 'zero reservation' => [static fn () => new ResourceBudget(defaultOutputReservationTokens: 0)];
        yield 'negative reservation' => [static fn () => new ResourceBudget(defaultOutputReservationTokens: -1)];
        yield 'cost wrong type' => [static fn () => ResourceBudget::fromArray(['max_cost_usd' => 1.2])];
        yield 'nullable integer wrong type' => [static fn () => ResourceBudget::fromArray(['max_input_tokens' => '10'])];
        yield 'integer wrong type' => [static fn () => ResourceBudget::fromArray(['default_output_reservation_tokens' => '10'])];
        yield 'boolean wrong type' => [static fn () => ResourceBudget::fromArray(['require_complete_metrics' => 1])];
        yield 'pricing boolean wrong type' => [static fn () => ResourceBudget::fromArray(['require_known_pricing' => 'yes'])];
    }

    public function test_delivery_record_serializes_present_and_absent_optional_fields(): void
    {
        $available = new DateTimeImmutable('2026-08-08T10:00:00+00:00');
        $created = new DateTimeImmutable('2026-08-08T09:00:00+00:00');
        $updated = new DateTimeImmutable('2026-08-08T11:00:00+00:00');
        $lease = new DateTimeImmutable('2026-08-08T10:05:00+00:00');
        $delivered = new DateTimeImmutable('2026-08-08T10:04:00+00:00');
        $record = new DeliveryRecord(
            'delivery-1',
            'dedupe-1',
            'invocation',
            'delivered',
            2,
            $available,
            $created,
            $updated,
            InvocationId::fromString('invocation-1'),
            'WorkflowCompleted',
            $lease,
            $delivered,
            'previous_error',
        );
        $expected = [
            'schema_version' => 1,
            'id' => 'delivery-1',
            'deduplication_key' => 'dedupe-1',
            'kind' => 'invocation',
            'status' => 'delivered',
            'attempts' => 2,
            'invocation_id' => 'invocation-1',
            'event_type' => 'WorkflowCompleted',
            'available_at' => '2026-08-08T10:00:00+00:00',
            'lease_expires_at' => '2026-08-08T10:05:00+00:00',
            'delivered_at' => '2026-08-08T10:04:00+00:00',
            'last_error_code' => 'previous_error',
            'created_at' => '2026-08-08T09:00:00+00:00',
            'updated_at' => '2026-08-08T11:00:00+00:00',
        ];

        self::assertSame($expected, $record->toArray());
        self::assertSame($expected, $record->jsonSerialize());

        $empty = new DeliveryRecord('id', 'key', 'event', 'pending', 0, $available, $created, $updated);
        self::assertNull($empty->toArray()['invocation_id']);
        self::assertNull($empty->toArray()['event_type']);
        self::assertNull($empty->toArray()['lease_expires_at']);
        self::assertNull($empty->toArray()['delivered_at']);
        self::assertNull($empty->toArray()['last_error_code']);
    }

    public function test_unit_card_round_trips_every_field(): void
    {
        $card = $this->card();
        $expected = [
            'unit_id' => 'unit-1',
            'source_order' => 1,
            'summary' => 'Summary',
            'requirements_covered' => ['requirement'],
            'facts_added' => ['fact'],
            'decisions_added' => ['decision'],
            'hooks' => ['hook'],
            'transition' => 'Next',
            'content_hash' => 'hash-1',
        ];

        self::assertSame($expected, $card->toArray());
        self::assertSame($expected, UnitCard::fromArray($expected)->toArray());
    }

    #[DataProvider('invalidCards')]
    public function test_unit_card_rejects_invalid_constructor_and_payload(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidCards(): iterable
    {
        yield 'empty id' => [static fn () => new UnitCard(' ', 1, '', [], [], [], [], '', 'hash')];
        yield 'zero order' => [static fn () => new UnitCard('unit', 0, '', [], [], [], [], '', 'hash')];
        yield 'empty hash' => [static fn () => new UnitCard('unit', 1, '', [], [], [], [], '', ' ')];
        yield 'missing scalar' => [static fn () => UnitCard::fromArray([])];
        yield 'wrong scalar type' => [static fn () => UnitCard::fromArray(['unit_id' => 1, 'source_order' => '1', 'summary' => 1, 'transition' => 1, 'content_hash' => 1])];
        yield 'collection wrong type' => [static fn () => UnitCard::fromArray(['unit_id' => 'unit', 'source_order' => 1, 'summary' => '', 'transition' => '', 'content_hash' => 'hash', 'facts_added' => 'fact'])];
        yield 'collection item wrong type' => [static fn () => UnitCard::fromArray(['unit_id' => 'unit', 'source_order' => 1, 'summary' => '', 'transition' => '', 'content_hash' => 'hash', 'facts_added' => [1]])];
    }

    public function test_memory_delta_trims_deduplicates_and_round_trips_entities(): void
    {
        $payload = [
            'facts_added' => [' fact ', 'fact', 'second'],
            'decisions_added' => [' decision '],
            'entities_changed' => [[
                'entity_id' => 'entity-1',
                'value' => 'value-1',
                'expected_version' => 2,
            ]],
            'loops_opened' => [' loop '],
            'loops_resolved' => [' resolved '],
            'requirements_covered' => [' covered '],
            'requirements_violated' => [' violated '],
        ];
        $delta = MemoryDelta::fromArray($payload);
        $expected = [
            'facts_added' => ['fact', 'second'],
            'decisions_added' => ['decision'],
            'entities_changed' => [[
                'entity_id' => 'entity-1',
                'value' => 'value-1',
                'expected_version' => 2,
            ]],
            'loops_opened' => ['loop'],
            'loops_resolved' => ['resolved'],
            'requirements_covered' => ['covered'],
            'requirements_violated' => ['violated'],
        ];

        self::assertSame($expected, $delta->toArray());
        self::assertSame($expected, (new MemoryDelta(
            factsAdded: ['fact', 'second'],
            decisionsAdded: ['decision'],
            entitiesChanged: [new EntityMutation('entity-1', 'value-1', 2)],
            loopsOpened: ['loop'],
            loopsResolved: ['resolved'],
            requirementsCovered: ['covered'],
            requirementsViolated: ['violated'],
        ))->toArray());
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidDeltas')]
    public function test_memory_delta_rejects_malformed_collections(array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        MemoryDelta::fromArray($payload);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidDeltas(): iterable
    {
        yield 'entities scalar' => [['entities_changed' => 'entity']];
        yield 'entity scalar' => [['entities_changed' => ['entity']]];
        yield 'entity list object' => [['entities_changed' => [['entity', 'value', 1]]]];
        foreach (['facts_added', 'decisions_added', 'loops_opened', 'loops_resolved', 'requirements_covered', 'requirements_violated'] as $field) {
            yield "{$field} scalar" => [[$field => 'item']];
            yield "{$field} non string" => [[$field => [1]]];
            yield "{$field} empty" => [[$field => [' ']]];
        }
    }

    public function test_operation_call_round_trips_exactly(): void
    {
        $expected = [
            'id' => 'call.one',
            'operation_id' => 'rick.text',
            'operation_version' => '1.2.0',
            'input_keys' => ['source', 'context'],
            'output_key' => 'draft.main',
            'parameters' => ['temperature' => 0.2, 'nested' => ['enabled' => true]],
        ];
        $call = OperationCall::fromArray($expected);

        self::assertSame($expected, $call->toArray());
        self::assertSame($expected, (new OperationCall(
            'call.one',
            'rick.text',
            '1.2.0',
            ['source', 'context'],
            'draft.main',
            ['temperature' => 0.2, 'nested' => ['enabled' => true]],
        ))->toArray());
    }

    #[DataProvider('invalidOperationCalls')]
    public function test_operation_call_rejects_invalid_constructor_and_payload(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidOperationCalls(): iterable
    {
        foreach (['Bad', '1bad', 'bad value', ''] as $invalid) {
            yield "invalid id {$invalid}" => [static fn () => new OperationCall($invalid, 'rick.text', null, [], 'output')];
            yield "invalid operation {$invalid}" => [static fn () => new OperationCall('call', $invalid, null, [], 'output')];
            yield "invalid output {$invalid}" => [static fn () => new OperationCall('call', 'rick.text', null, [], $invalid)];
        }
        yield 'input keys missing' => [static fn () => OperationCall::fromArray([])];
        yield 'input keys scalar' => [static fn () => OperationCall::fromArray(['input_keys' => 'key'])];
        yield 'input key non string' => [static fn () => OperationCall::fromArray(['input_keys' => [1]])];
        yield 'required scalar missing' => [static fn () => OperationCall::fromArray(['input_keys' => []])];
        yield 'version wrong type' => [static fn () => OperationCall::fromArray(['id' => 'call', 'operation_id' => 'rick.text', 'operation_version' => 1, 'input_keys' => [], 'output_key' => 'output'])];
        yield 'parameters scalar' => [static fn () => OperationCall::fromArray(['id' => 'call', 'operation_id' => 'rick.text', 'input_keys' => [], 'output_key' => 'output', 'parameters' => 'value'])];
        yield 'parameters list' => [static fn () => OperationCall::fromArray(['id' => 'call', 'operation_id' => 'rick.text', 'input_keys' => [], 'output_key' => 'output', 'parameters' => ['value']])];
    }

    public function test_token_usage_keeps_explicit_totals_arithmetic_and_cache_floor(): void
    {
        $usage = new TokenUsage(10, 5, 20, 3, 2, 1);
        self::assertSame([
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 20,
            'cached_input_tokens' => 3,
            'cache_write_input_tokens' => 2,
            'reasoning_tokens' => 1,
        ], $usage->toArray());
        self::assertSame(7, $usage->uncachedInputTokens());
        self::assertSame(0, (new TokenUsage(2, cachedInputTokens: 3))->uncachedInputTokens());
        self::assertSame([
            'input_tokens' => 11,
            'output_tokens' => 7,
            'total_tokens' => 23,
            'cached_input_tokens' => 4,
            'cache_write_input_tokens' => 4,
            'reasoning_tokens' => 4,
        ], $usage->plus(new TokenUsage(1, 2, 3, 1, 2, 3))->toArray());
        self::assertSame(TokenUsage::zero()->toArray(), new TokenUsage()->toArray());
        self::assertSame(15, (new TokenUsage(10, 5))->totalTokens);
    }

    /** @param array<string, int> $arguments */
    #[DataProvider('invalidTokenUsage')]
    public function test_token_usage_rejects_each_negative_counter(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TokenUsage(...$arguments);
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function invalidTokenUsage(): iterable
    {
        foreach (['inputTokens', 'outputTokens', 'totalTokens', 'cachedInputTokens', 'cacheWriteInputTokens', 'reasoningTokens'] as $field) {
            yield $field => [[$field => -1]];
        }
    }

    public function test_artifact_and_decision_contracts_are_exact(): void
    {
        $candidate = $this->candidate();
        $artifact = Artifact::fromCandidate($candidate);
        self::assertSame('draft.main', $artifact->key);
        self::assertSame('draft', $artifact->type->toString());
        self::assertSame('Content', $artifact->content);
        self::assertSame(['answer' => 42], $artifact->payload);
        self::assertSame([
            'candidate_id' => 'candidate-1',
            'step_id' => 'step-1',
            'title' => 'Title',
            'summary' => 'Summary',
            'output_key' => 'draft.main',
            'custom' => true,
        ], $artifact->metadata);
        self::assertSame(1, $artifact->version);
        self::assertSame($artifact->toArray(), $artifact->jsonSerialize());

        $explicit = Artifact::fromCandidate($candidate, 'explicit');
        self::assertSame('explicit', $explicit->key);
        $decision = new CandidateDecision(
            StepId::fromString('step-1'),
            CandidateId::fromString('candidate-1'),
            0,
            'Reason',
            'manual',
            'seed',
        );
        self::assertSame([
            'schema_version' => 1,
            'step_id' => 'step-1',
            'selected_candidate_id' => 'candidate-1',
            'score' => 0.0,
            'reason' => 'Reason',
            'policy' => 'manual',
            'selection_seed' => 'seed',
        ], $decision->toArray());
        self::assertSame($decision->toArray(), $decision->jsonSerialize());
        self::assertSame(100.0, (new CandidateDecision(StepId::fromString('step'), CandidateId::fromString('candidate'), 100, 'Maximum'))->score);
    }

    #[DataProvider('invalidArtifactsAndDecisions')]
    public function test_artifact_and_decision_reject_invalid_boundaries(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidArtifactsAndDecisions(): iterable
    {
        yield 'artifact key' => [static fn () => new Artifact('Bad Key', ArtifactType::fromString('text'), '')];
        yield 'artifact zero version' => [static fn () => new Artifact('valid', ArtifactType::fromString('text'), '', version: 0)];
        yield 'artifact negative version' => [static fn () => new Artifact('valid', ArtifactType::fromString('text'), '', version: -1)];
        yield 'negative score' => [static fn () => new CandidateDecision(StepId::fromString('step'), CandidateId::fromString('candidate'), -0.1, 'Reason')];
        yield 'score above maximum' => [static fn () => new CandidateDecision(StepId::fromString('step'), CandidateId::fromString('candidate'), 100.1, 'Reason')];
    }

    private function card(): UnitCard
    {
        return new UnitCard(
            'unit-1',
            1,
            'Summary',
            ['requirement'],
            ['fact'],
            ['decision'],
            ['hook'],
            'Next',
            'hash-1',
        );
    }

    private function candidate(): Candidate
    {
        return new Candidate(
            CandidateId::fromString('candidate-1'),
            StepId::fromString('step-1'),
            ArtifactType::fromString('draft'),
            'Title',
            'Summary',
            ['answer' => 42],
            'Content',
            'random',
            'interpretation',
            ['output_key' => 'draft.main', 'custom' => true],
        );
    }
}
