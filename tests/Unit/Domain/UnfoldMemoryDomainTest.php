<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Rick\Laravel\Domain\Exception\InvalidStateTransitionException;
use Rick\Laravel\Domain\Execution\UnfoldPhase;
use Rick\Laravel\Domain\Execution\UnfoldProgress;
use Rick\Laravel\Domain\Execution\UnfoldUnit;
use Rick\Laravel\Domain\Memory\UnitCard;
use Rick\Laravel\Domain\Memory\WorkingMemory;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;

final class UnfoldMemoryDomainTest extends TestCase
{
    public function test_working_memory_round_trips_and_projects_exact_recent_entries(): void
    {
        $memory = new WorkingMemory(
            version: 4,
            facts: ['fact-1', 'fact-2', 'fact-3'],
            decisions: ['decision-1', 'decision-2', 'decision-3'],
            entities: [
                'entity-1' => ['value' => 'one', 'version' => 1],
                'entity-2' => ['value' => 'two', 'version' => 2],
                'entity-3' => ['value' => 'three', 'version' => 3],
            ],
            openLoops: ['open-1', 'open-2', 'open-3'],
            resolvedLoops: ['resolved-1'],
            requirementsCovered: ['requirement-1', 'requirement-2', 'requirement-3'],
            unitCards: [$this->card('unit-1', 1), $this->card('unit-2', 2), $this->card('unit-3', 3)],
        );

        $expected = [
            'version' => 4,
            'facts' => ['fact-1', 'fact-2', 'fact-3'],
            'decisions' => ['decision-1', 'decision-2', 'decision-3'],
            'entities' => [
                'entity-1' => ['value' => 'one', 'version' => 1],
                'entity-2' => ['value' => 'two', 'version' => 2],
                'entity-3' => ['value' => 'three', 'version' => 3],
            ],
            'open_loops' => ['open-1', 'open-2', 'open-3'],
            'resolved_loops' => ['resolved-1'],
            'requirements_covered' => ['requirement-1', 'requirement-2', 'requirement-3'],
            'unit_cards' => [
                $this->card('unit-1', 1)->toArray(),
                $this->card('unit-2', 2)->toArray(),
                $this->card('unit-3', 3)->toArray(),
            ],
        ];

        self::assertSame($expected, $memory->toArray());
        self::assertSame($expected, WorkingMemory::fromArray($expected)->toArray());
        self::assertSame(hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $memory->hash());
        self::assertSame([
            'version' => 4,
            'facts' => ['fact-2', 'fact-3'],
            'decisions' => ['decision-2', 'decision-3'],
            'entities' => [
                'entity-2' => ['value' => 'two', 'version' => 2],
                'entity-3' => ['value' => 'three', 'version' => 3],
            ],
            'open_loops' => ['open-2', 'open-3'],
            'requirements_covered' => ['requirement-2', 'requirement-3'],
        ], $memory->semanticLedgerProjection(2));
        self::assertSame([
            'version' => 4,
            'facts' => ['fact-3'],
            'decisions' => ['decision-3'],
            'entities' => ['entity-3' => ['value' => 'three', 'version' => 3]],
            'open_loops' => ['open-3'],
            'requirements_covered' => ['requirement-3'],
            'recent_unit_cards' => [$this->card('unit-3', 3)->toArray()],
        ], $memory->promptProjection(1, 1));
        self::assertSame([
            $this->card('unit-2', 2)->toArray(),
            $this->card('unit-3', 3)->toArray(),
        ], $memory->recentUnitCardsProjection(2));
        self::assertSame(WorkingMemory::empty()->toArray(), (new WorkingMemory)->toArray());
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidMemories')]
    public function test_working_memory_rejects_malformed_persisted_data(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkingMemory::fromArray($data);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidMemories(): iterable
    {
        $valid = [
            'version' => 1,
            'facts' => [],
            'decisions' => [],
            'entities' => [],
            'open_loops' => [],
            'resolved_loops' => [],
            'requirements_covered' => [],
            'unit_cards' => [],
        ];

        yield 'missing version' => [array_diff_key($valid, ['version' => true])];
        yield 'string version' => [array_replace($valid, ['version' => '1'])];
        yield 'cards are scalar' => [array_replace($valid, ['unit_cards' => 'card'])];
        yield 'entities are scalar' => [array_replace($valid, ['entities' => 'entity'])];
        yield 'entity state is scalar' => [array_replace($valid, ['entities' => ['id' => 'state']])];
        yield 'entity value is scalar' => [array_replace($valid, ['entities' => ['id' => ['value' => 1, 'version' => 1]]])];
        yield 'entity version is scalar' => [array_replace($valid, ['entities' => ['id' => ['value' => 'v', 'version' => '1']]])];
        yield 'facts are scalar' => [array_replace($valid, ['facts' => 'fact'])];
        yield 'facts contain scalar' => [array_replace($valid, ['facts' => [1]])];
        yield 'card is scalar' => [array_replace($valid, ['unit_cards' => ['card']])];
        yield 'card has list keys' => [array_replace($valid, ['unit_cards' => [['unit-1', 1]]])];
    }

    public function test_unfold_unit_round_trips_every_field(): void
    {
        $unit = $this->unit('unit-1', 1);

        self::assertSame([
            'unit_id' => 'unit-1',
            'title' => 'Title unit-1',
            'source_order' => 1,
            'content' => 'Content unit-1',
            'constraints' => ['constraint'],
            'must_preserve' => ['preserve'],
            'target_words' => 100,
            'minimum_words' => 80,
            'maximum_words' => 120,
            'dependencies' => ['dependency'],
            'must_cover' => ['coverage'],
            'must_not_repeat' => ['repetition'],
            'evidence_queries' => ['query'],
            'memory_reads' => ['read'],
            'memory_writes' => ['write'],
        ], $unit->toArray());
        self::assertSame($unit->toArray(), UnfoldUnit::fromArray($unit->toArray())->toArray());
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidUnits')]
    public function test_unfold_unit_rejects_invalid_construction(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReflectionClass(UnfoldUnit::class))->newInstanceArgs(array_values($arguments));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidUnits(): iterable
    {
        $valid = [
            'id' => 'unit',
            'title' => 'Title',
            'sourceOrder' => 1,
            'content' => 'Content',
            'constraints' => [],
            'mustPreserve' => [],
            'targetWords' => null,
            'minimumWords' => null,
            'maximumWords' => null,
            'dependencies' => [],
            'mustCover' => [],
            'mustNotRepeat' => [],
            'evidenceQueries' => [],
            'memoryReads' => [],
            'memoryWrites' => [],
        ];

        yield 'empty id' => [array_replace($valid, ['id' => ' '])];
        yield 'empty title' => [array_replace($valid, ['title' => ' '])];
        yield 'zero order' => [array_replace($valid, ['sourceOrder' => 0])];
        yield 'negative order' => [array_replace($valid, ['sourceOrder' => -1])];
        yield 'minimum below one' => [array_replace($valid, ['minimumWords' => 0, 'targetWords' => 1, 'maximumWords' => 2])];
        yield 'minimum exceeds target' => [array_replace($valid, ['minimumWords' => 2, 'targetWords' => 1, 'maximumWords' => 3])];
        yield 'target exceeds maximum' => [array_replace($valid, ['minimumWords' => 1, 'targetWords' => 3, 'maximumWords' => 2])];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidUnitPayloads')]
    public function test_unfold_unit_rejects_malformed_payloads(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        UnfoldUnit::fromArray($data);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidUnitPayloads(): iterable
    {
        $valid = ['unit_id' => 'unit', 'title' => 'Title', 'source_order' => 1, 'content' => 'Content'];

        foreach (['unit_id', 'title', 'content'] as $field) {
            yield "{$field} missing" => [array_diff_key($valid, [$field => true])];
            yield "{$field} wrong type" => [array_replace($valid, [$field => 1])];
        }
        yield 'source order missing' => [array_diff_key($valid, ['source_order' => true])];
        yield 'source order wrong type' => [array_replace($valid, ['source_order' => '1'])];
        yield 'collection wrong type' => [array_replace($valid, ['constraints' => 'constraint'])];
        yield 'collection item wrong type' => [array_replace($valid, ['constraints' => [1]])];
        yield 'nullable integer wrong type' => [array_replace($valid, ['target_words' => '100'])];
    }

    public function test_unfold_progress_enforces_phase_transitions_and_cursor(): void
    {
        $first = $this->unit('unit-1', 1);
        $second = $this->unit('unit-2', 2);
        $memory = new WorkingMemory(version: 1, facts: ['remembered']);

        $explode = UnfoldProgress::needsExplosion();
        self::assertSame(UnfoldPhase::Explode, $explode->phase);
        self::assertFalse($explode->isComplete());
        self::assertSame([
            'phase' => 'explode',
            'units' => [],
            'unit_index' => 0,
            'selected_candidate_ids' => [],
            'memory' => WorkingMemory::empty()->toArray(),
        ], $explode->toArray());

        $generating = UnfoldProgress::forUnits([$first, $second]);
        self::assertSame($first->toArray(), $generating->currentUnit()->toArray());
        self::assertSame(UnfoldPhase::Judge, $generating->awaitingJudge()->phase);

        $secondUnit = $generating->accept(CandidateId::fromString('candidate-1'), $memory);
        self::assertSame(UnfoldPhase::Generate, $secondUnit->phase);
        self::assertSame(1, $secondUnit->unitIndex);
        self::assertSame(['candidate-1'], $secondUnit->selectedCandidateIds);
        self::assertSame($second->toArray(), $secondUnit->currentUnit()->toArray());
        self::assertSame($memory->toArray(), $secondUnit->memory->toArray());

        $complete = $secondUnit->awaitingJudge()->accept(CandidateId::fromString('candidate-2'), $memory);
        self::assertTrue($complete->isComplete());
        self::assertSame(UnfoldPhase::Complete, $complete->phase);
        self::assertSame(1, $complete->unitIndex);
        self::assertSame(['candidate-1', 'candidate-2'], $complete->selectedCandidateIds);
        self::assertSame($complete->toArray(), UnfoldProgress::fromArray($complete->toArray())->toArray());
    }

    #[DataProvider('invalidProgressOperations')]
    public function test_unfold_progress_rejects_invalid_states(callable $operation): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        $operation();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidProgressOperations(): iterable
    {
        $unit = static fn (): UnfoldUnit => new UnfoldUnit('unit', 'Title', 1, 'Content');

        yield 'empty units' => [static fn () => UnfoldProgress::forUnits([])];
        yield 'explode has no cursor' => [static fn () => UnfoldProgress::needsExplosion()->currentUnit()];
        yield 'explode cannot await judge' => [static fn () => UnfoldProgress::needsExplosion()->awaitingJudge()];
        yield 'explode cannot accept' => [static fn () => UnfoldProgress::needsExplosion()->accept(CandidateId::fromString('candidate'), WorkingMemory::empty())];
        yield 'judge cannot await judge twice' => [static fn () => UnfoldProgress::forUnits([$unit()])->awaitingJudge()->awaitingJudge()];
        yield 'complete cannot accept' => [static fn () => UnfoldProgress::forUnits([$unit()])->accept(CandidateId::fromString('candidate'), WorkingMemory::empty())->accept(CandidateId::fromString('again'), WorkingMemory::empty())];
        yield 'missing progress fields' => [static fn () => UnfoldProgress::fromArray([])];
        yield 'units wrong type' => [static fn () => UnfoldProgress::fromArray(['phase' => 'generate', 'units' => 'units', 'unit_index' => 0, 'selected_candidate_ids' => []])];
        yield 'selected wrong type' => [static fn () => UnfoldProgress::fromArray(['phase' => 'generate', 'units' => [], 'unit_index' => 0, 'selected_candidate_ids' => 'candidate'])];
        yield 'phase wrong type' => [static fn () => UnfoldProgress::fromArray(['phase' => 1, 'units' => [], 'unit_index' => 0, 'selected_candidate_ids' => []])];
        yield 'index wrong type' => [static fn () => UnfoldProgress::fromArray(['phase' => 'generate', 'units' => [], 'unit_index' => '0', 'selected_candidate_ids' => []])];
        yield 'unit is scalar' => [static fn () => UnfoldProgress::fromArray(['phase' => 'generate', 'units' => ['unit'], 'unit_index' => 0, 'selected_candidate_ids' => []])];
        yield 'unit has list keys' => [static fn () => UnfoldProgress::fromArray(['phase' => 'generate', 'units' => [['unit', 'Title']], 'unit_index' => 0, 'selected_candidate_ids' => []])];
        yield 'candidate is scalar' => [static fn () => UnfoldProgress::fromArray(['phase' => 'generate', 'units' => [], 'unit_index' => 0, 'selected_candidate_ids' => [1]])];
        yield 'memory has list keys' => [static fn () => UnfoldProgress::fromArray(['phase' => 'generate', 'units' => [], 'unit_index' => 0, 'selected_candidate_ids' => [], 'memory' => [['version', 1]]])];
    }

    private function card(string $id, int $order): UnitCard
    {
        return new UnitCard(
            $id,
            $order,
            "Summary {$id}",
            ['requirement'],
            ['fact'],
            ['decision'],
            ['hook'],
            "Transition {$id}",
            "hash-{$id}",
        );
    }

    private function unit(string $id, int $order): UnfoldUnit
    {
        return new UnfoldUnit(
            id: $id,
            title: "Title {$id}",
            sourceOrder: $order,
            content: "Content {$id}",
            constraints: ['constraint'],
            mustPreserve: ['preserve'],
            targetWords: 100,
            minimumWords: 80,
            maximumWords: 120,
            dependencies: ['dependency'],
            mustCover: ['coverage'],
            mustNotRepeat: ['repetition'],
            evidenceQueries: ['query'],
            memoryReads: ['read'],
            memoryWrites: ['write'],
        );
    }
}
