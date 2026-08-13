<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Memory;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Memory\MemoryMerger;
use Rick\Laravel\Domain\Exception\MemoryConflictDetectedException;
use Rick\Laravel\Domain\Execution\UnfoldUnit;
use Rick\Laravel\Domain\Memory\EntityMutation;
use Rick\Laravel\Domain\Memory\MemoryDelta;
use Rick\Laravel\Domain\Memory\UnitCard;
use Rick\Laravel\Domain\Memory\WorkingMemory;
use Rick\Laravel\Domain\Run\Candidate;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;

final class MemoryMergerTest extends TestCase
{
    public function test_it_merges_every_ledger_section_with_exact_versions_and_order(): void
    {
        $existingCard = new UnitCard(
            'unit-0',
            1,
            'Earlier summary',
            ['requirement-old'],
            ['fact-old'],
            ['decision-old'],
            ['loop-old'],
            'Earlier transition',
            'earlier-hash',
        );
        $memory = new WorkingMemory(
            version: 4,
            facts: ['fact-old', 'fact-shared'],
            decisions: ['decision-old', 'decision-shared'],
            entities: ['existing' => ['value' => 'before', 'version' => 2]],
            openLoops: ['loop-resolved', 'loop-stays'],
            resolvedLoops: ['loop-already-resolved'],
            requirementsCovered: ['requirement-old', 'requirement-shared'],
            unitCards: [$existingCard],
        );
        $delta = new MemoryDelta(
            factsAdded: ['fact-shared', 'fact-new'],
            decisionsAdded: ['decision-shared', 'decision-new'],
            entitiesChanged: [
                new EntityMutation('existing', 'after', 2),
                new EntityMutation('new', 'created', 0),
            ],
            loopsOpened: ['loop-stays', 'loop-new', 'loop-open-and-resolve'],
            loopsResolved: ['loop-resolved', 'loop-open-and-resolve'],
            requirementsCovered: ['requirement-shared', 'requirement-delta'],
        );
        $unit = new UnfoldUnit(
            'unit-2',
            'Second unit',
            2,
            'Source content',
            mustCover: ['requirement-unit', 'requirement-shared'],
        );
        $candidate = $this->candidate('Accepted summary', 'Accepted content');

        $merged = (new MemoryMerger)->commit($memory, $delta, $unit, $candidate);

        self::assertSame([
            'version' => 5,
            'facts' => ['fact-old', 'fact-shared', 'fact-new'],
            'decisions' => ['decision-old', 'decision-shared', 'decision-new'],
            'entities' => [
                'existing' => ['value' => 'after', 'version' => 3],
                'new' => ['value' => 'created', 'version' => 1],
            ],
            'open_loops' => ['loop-stays', 'loop-new'],
            'resolved_loops' => [
                'loop-already-resolved',
                'loop-resolved',
                'loop-open-and-resolve',
            ],
            'requirements_covered' => [
                'requirement-old',
                'requirement-shared',
                'requirement-unit',
                'requirement-delta',
            ],
            'unit_cards' => [
                $existingCard->toArray(),
                [
                    'unit_id' => 'unit-2',
                    'source_order' => 2,
                    'summary' => 'Accepted summary',
                    'requirements_covered' => [
                        'requirement-unit',
                        'requirement-shared',
                        'requirement-delta',
                    ],
                    'facts_added' => ['fact-shared', 'fact-new'],
                    'decisions_added' => ['decision-shared', 'decision-new'],
                    'hooks' => ['loop-stays', 'loop-new', 'loop-open-and-resolve'],
                    'transition' => 'Accepted summary',
                    'content_hash' => hash('sha256', 'Accepted content'),
                ],
            ],
        ], $merged->toArray());
        self::assertSame($memory->toArray(), [
            'version' => 4,
            'facts' => ['fact-old', 'fact-shared'],
            'decisions' => ['decision-old', 'decision-shared'],
            'entities' => ['existing' => ['value' => 'before', 'version' => 2]],
            'open_loops' => ['loop-resolved', 'loop-stays'],
            'resolved_loops' => ['loop-already-resolved'],
            'requirements_covered' => ['requirement-old', 'requirement-shared'],
            'unit_cards' => [$existingCard->toArray()],
        ]);
    }

    /** @param callable(): array{WorkingMemory, MemoryDelta, UnfoldUnit} $input */
    #[DataProvider('conflicts')]
    public function test_it_rejects_every_memory_conflict(
        callable $input,
        string $expectedCode,
        string $expectedMessage,
    ): void {
        [$memory, $delta, $unit] = $input();

        try {
            (new MemoryMerger)->commit(
                $memory,
                $delta,
                $unit,
                $this->candidate('Summary', 'Content'),
            );
            self::fail('Expected a memory conflict.');
        } catch (MemoryConflictDetectedException $exception) {
            self::assertSame($expectedCode, $exception->errorCode());
            self::assertSame($expectedMessage, $exception->getMessage());
            self::assertFalse($exception->retryable());
        }
    }

    /** @return iterable<string, array{callable(): array{WorkingMemory, MemoryDelta, UnfoldUnit}, string, string}> */
    public static function conflicts(): iterable
    {
        yield 'required coverage is violated' => [
            static fn (): array => [
                WorkingMemory::empty(),
                new MemoryDelta(requirementsViolated: ['required-b', 'other']),
                new UnfoldUnit('unit', 'Unit', 1, 'Content', mustCover: ['required-a', 'required-b']),
            ],
            'memory_required_coverage_violated',
            'Accepted unit violates required coverage: required-b.',
        ];
        yield 'same requirement is covered and violated' => [
            static fn (): array => [
                WorkingMemory::empty(),
                new MemoryDelta(
                    requirementsCovered: ['conflict', 'covered'],
                    requirementsViolated: ['other', 'conflict'],
                ),
                new UnfoldUnit('unit', 'Unit', 1, 'Content'),
            ],
            'memory_coverage_conflict',
            'Memory delta both covers and violates: conflict.',
        ];
        yield 'existing entity has stale version' => [
            static fn (): array => [
                new WorkingMemory(entities: ['entity' => ['value' => 'old', 'version' => 2]]),
                new MemoryDelta(entitiesChanged: [new EntityMutation('entity', 'new', 1)]),
                new UnfoldUnit('unit', 'Unit', 1, 'Content'),
            ],
            'memory_entity_version_conflict',
            'Memory entity [entity] expected version 1, actual version is 2.',
        ];
        yield 'new entity must expect version zero' => [
            static fn (): array => [
                WorkingMemory::empty(),
                new MemoryDelta(entitiesChanged: [new EntityMutation('new', 'value', 7)]),
                new UnfoldUnit('unit', 'Unit', 1, 'Content'),
            ],
            'memory_entity_version_conflict',
            'Memory entity [new] expected version 7, actual version is 0.',
        ];
        yield 'unknown loop cannot be resolved' => [
            static fn (): array => [
                new WorkingMemory(openLoops: ['known']),
                new MemoryDelta(loopsResolved: ['missing']),
                new UnfoldUnit('unit', 'Unit', 1, 'Content'),
            ],
            'memory_unknown_loop',
            'Memory delta resolves unknown loop [missing].',
        ];
    }

    private function candidate(string $summary, string $content): Candidate
    {
        return new Candidate(
            CandidateId::fromString('candidate'),
            StepId::fromString('unfold'),
            ArtifactType::fromString('section'),
            'Candidate',
            $summary,
            [],
            $content,
            'seed',
            'interpretation',
        );
    }
}
