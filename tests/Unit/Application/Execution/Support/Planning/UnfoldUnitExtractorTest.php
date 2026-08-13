<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Planning;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Planning\UnfoldUnitExtractor;

final class UnfoldUnitExtractorTest extends TestCase
{
    public function test_extracts_sorts_and_normalizes_string_and_object_units(): void
    {
        $units = (new UnfoldUnitExtractor)->fromValues([
            [
                'id' => 'second',
                'name' => ' Second ',
                'purpose' => ' Content ',
                'source_order' => 2,
                'constraints' => [' constraint ', ' '],
                'must_preserve' => [],
                'target_words' => 20,
                'minimum_words' => 10,
                'maximum_words' => 30,
                'dependencies' => ['first'],
                'must_cover' => ['requirement'],
                'must_not_repeat' => ['previous'],
                'evidence_queries' => ['evidence'],
                'memory_reads' => ['fact'],
                'memory_writes' => ['decision'],
            ],
            [
                'unit_id' => 'first',
                'title' => 'First',
                'content' => 'First content',
                'source_order' => 1,
            ],
            ' Third ',
        ], 3);

        self::assertSame([
            [
                'unit_id' => 'first',
                'title' => 'First',
                'source_order' => 1,
                'content' => 'First content',
                'constraints' => [],
                'must_preserve' => ['Preserve unit intent: First'],
                'target_words' => null,
                'minimum_words' => null,
                'maximum_words' => null,
                'dependencies' => [],
                'must_cover' => [],
                'must_not_repeat' => [],
                'evidence_queries' => [],
                'memory_reads' => [],
                'memory_writes' => [],
            ],
            [
                'unit_id' => 'second',
                'title' => 'Second',
                'source_order' => 2,
                'content' => 'Content',
                'constraints' => ['constraint'],
                'must_preserve' => ['Preserve unit intent: Second'],
                'target_words' => 20,
                'minimum_words' => 10,
                'maximum_words' => 30,
                'dependencies' => ['first'],
                'must_cover' => ['requirement'],
                'must_not_repeat' => ['previous'],
                'evidence_queries' => ['evidence'],
                'memory_reads' => ['fact'],
                'memory_writes' => ['decision'],
            ],
            [
                'unit_id' => 'unit_3',
                'title' => 'Third',
                'source_order' => 3,
                'content' => 'Third',
                'constraints' => [],
                'must_preserve' => ['Preserve unit intent: Third'],
                'target_words' => null,
                'minimum_words' => null,
                'maximum_words' => null,
                'dependencies' => [],
                'must_cover' => [],
                'must_not_repeat' => [],
                'evidence_queries' => [],
                'memory_reads' => [],
                'memory_writes' => [],
            ],
        ], array_map(static fn ($unit): array => $unit->toArray(), $units));
    }

    public function test_rejects_empty_oversized_duplicate_and_unresolved_unit_sets(): void
    {
        $extractor = new UnfoldUnitExtractor;
        $operations = [
            [static fn () => $extractor->fromValues([], 1), 'UNFOLD requires at least one valid unit.'],
            [static fn () => $extractor->fromValues(['one', 'two'], 1), 'UNFOLD produced 2 units; configured maximum is 1.'],
            [static fn () => $extractor->fromValues([
                ['unit_id' => 'same', 'title' => 'One'],
                ['unit_id' => 'same', 'title' => 'Two'],
            ], 2), 'UNFOLD unit id [same] is duplicated.'],
            [static fn () => $extractor->fromValues([
                ['unit_id' => 'first', 'title' => 'First', 'dependencies' => ['second']],
                ['unit_id' => 'second', 'title' => 'Second'],
            ], 2), 'UNFOLD unit [first] has unresolved or forward dependency [second].'],
            [static fn () => $extractor->fromValues([
                ['unit_id' => 'only', 'title' => 'Only', 'dependencies' => ['missing']],
            ], 1), 'UNFOLD unit [only] has unresolved or forward dependency [missing].'],
        ];

        foreach ($operations as [$operation, $message]) {
            try {
                $operation();
                self::fail('Invalid unit set was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_rejects_every_invalid_unit_field_shape(): void
    {
        $extractor = new UnfoldUnitExtractor;
        $values = [
            [42, 'UNFOLD unit must be a string or object.'],
            [['title' => ' '], 'UNFOLD unit title must not be empty.'],
            [['title' => 1], 'UNFOLD field [title] must be a string.'],
            [['title' => 'Title', 'content' => 1], 'UNFOLD field [content] must be a string.'],
            [['title' => 'Title', 'unit_id' => 1], 'UNFOLD field [unit_id] must be a string.'],
            [['title' => 'Title', 'source_order' => '1'], 'UNFOLD field [source_order] must be an integer.'],
            [['title' => 'Title', 'constraints' => [1]], 'UNFOLD string collection contains a non-string value.'],
            [['title' => 'Title', 'target_words' => '20'], 'UNFOLD field [target_words] must be an integer or null.'],
        ];

        foreach ($values as [$value, $message]) {
            try {
                $extractor->fromValues([$value], 1);
                self::fail('Invalid unit field was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_object_fallbacks_and_minimum_source_order_are_exact(): void
    {
        $units = (new UnfoldUnitExtractor)->fromValues([
            ['path' => 'Path title', 'source_order' => 0, 'must_preserve' => [' explicit ']],
            [],
        ], 2);

        self::assertSame('unit_1', $units[0]->id);
        self::assertSame('Path title', $units[0]->title);
        self::assertSame('Path title', $units[0]->content);
        self::assertSame(1, $units[0]->sourceOrder);
        self::assertSame(['explicit'], $units[0]->mustPreserve);
        self::assertSame('unit_2', $units[1]->id);
        self::assertSame('Unit 2', $units[1]->title);
        self::assertSame('Unit 2', $units[1]->content);
        self::assertSame(2, $units[1]->sourceOrder);
    }
}
