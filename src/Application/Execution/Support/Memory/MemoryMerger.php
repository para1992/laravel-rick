<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Memory;

use Rick\Laravel\Domain\Exception\MemoryConflictDetectedException;
use Rick\Laravel\Domain\Execution\UnfoldUnit;
use Rick\Laravel\Domain\Memory\MemoryDelta;
use Rick\Laravel\Domain\Memory\UnitCard;
use Rick\Laravel\Domain\Memory\WorkingMemory;
use Rick\Laravel\Domain\Run\Candidate;

final readonly class MemoryMerger
{
    public function commit(
        WorkingMemory $memory,
        MemoryDelta $delta,
        UnfoldUnit $unit,
        Candidate $candidate,
    ): WorkingMemory {
        $violatedRequired = array_values(array_intersect(
            $unit->mustCover,
            $delta->requirementsViolated,
        ));
        if ($violatedRequired !== []) {
            throw new MemoryConflictDetectedException(
                'Accepted unit violates required coverage: '
                .implode(', ', $violatedRequired).'.',
                'memory_required_coverage_violated',
            );
        }

        $coveredAndViolated = array_values(array_intersect(
            $delta->requirementsCovered,
            $delta->requirementsViolated,
        ));
        if ($coveredAndViolated !== []) {
            throw new MemoryConflictDetectedException(
                'Memory delta both covers and violates: '
                .implode(', ', $coveredAndViolated).'.',
                'memory_coverage_conflict',
            );
        }

        $entities = $memory->entities;
        foreach ($delta->entitiesChanged as $mutation) {
            $exists = isset($entities[$mutation->id]);
            $currentVersion = $entities[$mutation->id]['version'] ?? 0;
            if ($mutation->expectedVersion !== $currentVersion) {
                throw new MemoryConflictDetectedException(sprintf(
                    'Memory entity [%s] expected version %d, actual version is %d.',
                    $mutation->id,
                    $mutation->expectedVersion,
                    $currentVersion,
                ), 'memory_entity_version_conflict');
            }
            $entities[$mutation->id] = [
                'value' => $mutation->value,
                'version' => $currentVersion + 1,
            ];
        }

        $openLoops = array_values(array_unique([
            ...$memory->openLoops,
            ...$delta->loopsOpened,
        ]));
        foreach ($delta->loopsResolved as $loop) {
            $index = array_search($loop, $openLoops, true);
            if ($index === false) {
                throw new MemoryConflictDetectedException(
                    "Memory delta resolves unknown loop [{$loop}].",
                    'memory_unknown_loop',
                );
            }
            unset($openLoops[$index]);
        }

        $requirements = array_values(array_unique([
            ...$memory->requirementsCovered,
            ...$unit->mustCover,
            ...$delta->requirementsCovered,
        ]));
        $card = new UnitCard(
            $unit->id,
            $unit->sourceOrder,
            $candidate->summary,
            array_values(array_unique([
                ...$unit->mustCover,
                ...$delta->requirementsCovered,
            ])),
            $delta->factsAdded,
            $delta->decisionsAdded,
            $delta->loopsOpened,
            $candidate->summary,
            hash('sha256', $candidate->content),
        );

        return new WorkingMemory(
            $memory->version + 1,
            array_values(array_unique([...$memory->facts, ...$delta->factsAdded])),
            array_values(array_unique([...$memory->decisions, ...$delta->decisionsAdded])),
            $entities,
            array_values($openLoops),
            array_values(array_unique([...$memory->resolvedLoops, ...$delta->loopsResolved])),
            $requirements,
            [...$memory->unitCards, $card],
        );
    }
}
