<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Quality;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Quality\ContentDistinctness;

final class ContentDistinctnessTest extends TestCase
{
    public function test_empty_short_and_repeated_content_produce_exact_bounded_signatures(): void
    {
        $distinctness = new ContentDistinctness;

        self::assertSame([
            'algorithm' => 'normalized_sha256_bottom_k_5gram_v1',
            'content_hash' => hash('sha256', ''),
            'word_count' => 0,
            'shingle_hashes' => [],
        ], $distinctness->signature(" \n / "));
        self::assertSame([], $distinctness->signature('one two three four')['shingle_hashes']);
        self::assertCount(1, $distinctness->signature(implode(' ', array_fill(0, 20, 'same')))['shingle_hashes']);
    }

    public function test_signature_uses_the_versioned_five_word_bottom_96_algorithm(): void
    {
        $content = '  '.implode(' / ', array_map(
            static fn (int $index): string => "TOKEN-{$index}",
            range(1, 105),
        )).'  ';
        $normalized = implode(' ', array_map(
            static fn (int $index): string => "token {$index}",
            range(1, 105),
        ));
        $words = explode(' ', $normalized);
        $expectedShingles = [];
        for ($index = 0; $index <= count($words) - 5; $index++) {
            $expectedShingles[] = substr(hash(
                'sha256',
                implode(' ', array_slice($words, $index, 5)),
            ), 0, 16);
        }
        $expectedShingles = array_values(array_unique($expectedShingles));
        sort($expectedShingles);

        $signature = (new ContentDistinctness)->signature($content);

        self::assertSame('normalized_sha256_bottom_k_5gram_v1', $signature['algorithm']);
        self::assertSame(hash('sha256', $normalized), $signature['content_hash']);
        self::assertSame(210, $signature['word_count']);
        self::assertSame(array_slice($expectedShingles, 0, 96), $signature['shingle_hashes']);
        self::assertCount(96, $signature['shingle_hashes']);
    }

    public function test_it_detects_exact_and_high_similarity_without_persisting_source_text(): void
    {
        $distinctness = new ContentDistinctness;
        $original = implode(' ', array_map(
            static fn (int $index): string => 'narrative-token-'.$index,
            range(1, 80),
        ));
        $signature = $distinctness->signature($original);
        $policy = ['prior_signatures' => [$signature]];

        self::assertSame('exact_duplicate', $distinctness->violation($original, $policy));
        self::assertSame(
            'high_similarity',
            $distinctness->violation($original.' one changed ending', $policy),
        );
        self::assertStringNotContainsString('narrative-token', json_encode(
            $signature,
            JSON_THROW_ON_ERROR,
        ));
    }

    public function test_it_accepts_distinct_content(): void
    {
        $distinctness = new ContentDistinctness;
        $first = implode(' ', array_fill(0, 30, 'first-scene-language'));
        $second = implode(' ', array_fill(0, 30, 'different-fourth-beat'));

        self::assertNull($distinctness->violation($second, [
            'prior_signatures' => [$distinctness->signature($first)],
        ]));
    }

    public function test_it_detects_a_bounded_restatement_of_the_source_unit(): void
    {
        $distinctness = new ContentDistinctness;
        $unit = 'Mara enters the observatory and discovers the broken emergency radio';

        self::assertTrue($distinctness->restates(
            $unit,
            $distinctness->signature($unit),
        ));
        self::assertFalse($distinctness->restates(
            implode(' ', array_fill(
                0,
                12,
                'Mara crosses the dark room while rain strikes the glass and the radio emits a voice',
            )),
            $distinctness->signature($unit),
        ));
    }

    public function test_similarity_boundaries_are_enforced_exactly(): void
    {
        $distinctness = new ContentDistinctness;
        $candidate = implode(' ', array_map(
            static fn (int $index): string => "word{$index}",
            range(1, 20),
        ));
        $signature = $distinctness->signature($candidate);
        $hashes = $signature['shingle_hashes'];

        self::assertSame('high_similarity', $distinctness->violation($candidate.' changed', [
            'prior_signatures' => [[
                'content_hash' => str_repeat('0', 64),
                'word_count' => 20,
                'shingle_hashes' => array_slice($hashes, 0, 12),
            ]],
        ]));
        self::assertNull($distinctness->violation($candidate.' changed', [
            'prior_signatures' => [[
                'content_hash' => str_repeat('0', 64),
                'word_count' => 19,
                'shingle_hashes' => array_slice($hashes, 0, 12),
            ]],
        ]));
        self::assertNull($distinctness->violation($candidate.' changed', [
            'prior_signatures' => [[
                'content_hash' => str_repeat('0', 64),
                'word_count' => 20,
                'shingle_hashes' => array_slice($hashes, 0, 11),
            ]],
        ]));

        $atThreshold = [
            ...array_slice($hashes, 0, 13),
            'not-a-candidate-1',
            'not-a-candidate-2',
            'not-a-candidate-3',
        ];
        self::assertTrue($distinctness->restates($candidate, [
            'content_hash' => str_repeat('0', 64),
            'word_count' => 20,
            'shingle_hashes' => $atThreshold,
        ]));
        self::assertFalse($distinctness->restates($candidate, [
            'content_hash' => str_repeat('0', 64),
            'word_count' => 20,
            'shingle_hashes' => [
                ...array_slice($hashes, 0, 11),
                'not-a-candidate-1',
                'not-a-candidate-2',
                'not-a-candidate-3',
                'not-a-candidate-4',
            ],
        ]));
    }

    public function test_malformed_and_mixed_signatures_fail_closed_or_are_skipped_independently(): void
    {
        $distinctness = new ContentDistinctness;
        $candidate = implode(' ', array_map(
            static fn (int $index): string => "candidate{$index}",
            range(1, 24),
        ));
        $signature = $distinctness->signature($candidate);

        self::assertNull($distinctness->violation($candidate, ['prior_signatures' => []]));
        foreach ([null, ['signature' => []]] as $prior) {
            try {
                $distinctness->violation($candidate, ['prior_signatures' => $prior]);
                self::fail('A non-list prior signature collection was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('list of prior signatures', $error->getMessage());
            }
        }
        try {
            $distinctness->violation($candidate, ['prior_signatures' => ['signature']]);
            self::fail('A scalar signature was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('must be an object', $error->getMessage());
        }

        $skipped = [
            ['content_hash' => 1, 'word_count' => '24', 'shingle_hashes' => $signature['shingle_hashes']],
            ['content_hash' => null, 'word_count' => 24, 'shingle_hashes' => 'hashes'],
            ['content_hash' => null, 'word_count' => 24, 'shingle_hashes' => ['hash' => 'value']],
            ['content_hash' => null, 'word_count' => 19, 'shingle_hashes' => $signature['shingle_hashes']],
            ['content_hash' => null, 'word_count' => 24, 'shingle_hashes' => [1, 2, 3, ...array_slice($signature['shingle_hashes'], 0, 11)]],
            [
                'content_hash' => str_repeat('0', 64),
                'word_count' => 24,
                'shingle_hashes' => [
                    ...array_slice($signature['shingle_hashes'], 0, 11),
                    ...array_map(static fn (int $index): string => "foreign{$index}", range(1, 9)),
                ],
            ],
        ];
        self::assertNull($distinctness->violation($candidate, [
            'prior_signatures' => [
                ...$skipped,
                [
                    'content_hash' => str_repeat('1', 64),
                    'word_count' => 24,
                    'shingle_hashes' => [
                        42,
                        ...array_slice($signature['shingle_hashes'], 0, 11),
                        ...array_map(static fn (int $index): string => "other{$index}", range(1, 9)),
                    ],
                ],
            ],
        ]));

        self::assertSame('exact_duplicate', $distinctness->violation($candidate, [
            'prior_signatures' => [
                ['word_count' => 'invalid', 'shingle_hashes' => []],
                ['word_count' => 24, 'shingle_hashes' => []],
                $signature,
            ],
        ]));
        self::assertSame('high_similarity', $distinctness->violation($candidate, [
            'prior_signatures' => [[
                'content_hash' => str_repeat('0', 64),
                'word_count' => 24,
                'shingle_hashes' => [
                    1,
                    2,
                    3,
                    4,
                    ...array_slice($signature['shingle_hashes'], 0, 12),
                ],
            ]],
        ]));
        self::assertSame('high_similarity', $distinctness->violation($candidate, [
            'prior_signatures' => [[
                'content_hash' => str_repeat('0', 64),
                'word_count' => 24,
                'shingle_hashes' => [
                    ...array_slice($signature['shingle_hashes'], 0, 12),
                    'foreign-1',
                    'foreign-2',
                    'foreign-3',
                ],
            ]],
        ]));
    }

    public function test_restatement_validates_shape_length_and_filtered_similarity_boundaries(): void
    {
        $distinctness = new ContentDistinctness;
        $source = 'one two three four five six seven eight nine ten';
        $signature = $distinctness->signature($source);

        foreach ([
            ['word_count' => '10', 'shingle_hashes' => $signature['shingle_hashes']],
            ['word_count' => 10, 'shingle_hashes' => 'hashes'],
            ['word_count' => 10, 'shingle_hashes' => ['hash' => 'value']],
        ] as $invalid) {
            try {
                $distinctness->restates('different content', $invalid);
                self::fail('An invalid source signature was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('signature is invalid', $error->getMessage());
            }
        }

        self::assertFalse($distinctness->restates(implode(' ', range(1, 31)), $signature));
        self::assertTrue($distinctness->restates('one two', $distinctness->signature('one two')));
        self::assertFalse($distinctness->restates('one two three four five', [
            'content_hash' => str_repeat('0', 64),
            'word_count' => 10,
            'shingle_hashes' => [42, ...array_slice($signature['shingle_hashes'], 0, 3)],
        ]));
        self::assertTrue($distinctness->restates($source.' eleven', [
            'content_hash' => str_repeat('0', 64),
            'word_count' => 10,
            'shingle_hashes' => [42, ...$signature['shingle_hashes']],
        ]));
        self::assertTrue($distinctness->restates($source, [
            'content_hash' => str_repeat('0', 64),
            'word_count' => 10,
            'shingle_hashes' => [1, 2, 3, 4, ...$signature['shingle_hashes']],
        ]));
        self::assertFalse($distinctness->restates($source, [
            'content_hash' => str_repeat('0', 64),
            'word_count' => 10,
            'shingle_hashes' => array_slice($signature['shingle_hashes'], 0, 4),
        ]));

        $thirtyWordSource = implode(' ', array_map(
            static fn (int $index): string => "source{$index}",
            range(1, 30),
        ));
        $sourceSignature = $distinctness->signature($thirtyWordSource);
        self::assertFalse($distinctness->restates(
            $thirtyWordSource.' '.implode(' ', array_map(
                static fn (int $index): string => "filler{$index}",
                range(1, 25),
            )),
            $sourceSignature,
        ));
        self::assertFalse($distinctness->restates(
            $source.' '.implode(' ', array_map(
                static fn (int $index): string => "overflow{$index}",
                range(1, 21),
            )),
            $signature,
        ));
        self::assertSame(
            hash('sha256', 'normalized edges'),
            $distinctness->signature('/// Normalized edges !!!')['content_hash'],
        );
    }

    public function test_source_grounded_expansion_is_not_misclassified_as_restatement(): void
    {
        $distinctness = new ContentDistinctness;
        $source = implode(' ', array_map(
            static fn (int $index): string => "outline{$index}",
            range(1, 30),
        ));
        $expansion = $source.' '.implode(' ', array_map(
            static fn (int $index): string => "new-prose{$index}",
            range(1, 30),
        ));

        self::assertFalse($distinctness->restates(
            $expansion,
            $distinctness->signature($source),
        ));
        self::assertTrue($distinctness->restates(
            $source.' small addition',
            $distinctness->signature($source),
        ));
    }
}
