<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Support\Grounding;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Support\Grounding\ExactQuoteVerifier;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingClaim;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingEvidence;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingReport;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingSegmenter;
use Rick\Laravel\Application\Execution\Support\Grounding\GroundingUnit;
use Rick\Laravel\Domain\Run\Artifact;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;

final class GroundingSupportTest extends TestCase
{
    public function test_claim_report_evidence_and_unit_have_exact_serialized_contracts(): void
    {
        $claim = GroundingClaim::fromArray([
            'unit_id' => 'unit-1',
            'claim' => 'Supported fact',
            'source_quote' => 'Exact source quote',
            'verdict' => 'supported',
            'evidence' => [
                'discarded scalar',
                ['artifact_key' => 'source', 'quote' => 'Exact evidence quote'],
                ['artifact_key' => 7, 'quote' => false],
            ],
        ]);

        self::assertSame([
            'unit_id' => 'unit-1',
            'claim' => 'Supported fact',
            'source_quote' => 'Exact source quote',
            'verdict' => 'supported',
            'evidence' => [
                ['artifact_key' => 'source', 'quote' => 'Exact evidence quote'],
                ['artifact_key' => '', 'quote' => ''],
            ],
        ], $claim->toArray());
        self::assertSame([
            'unit_id' => '',
            'claim' => '',
            'source_quote' => '',
            'verdict' => '',
            'evidence' => [],
        ], GroundingClaim::fromArray(['evidence' => 'invalid'])->toArray());

        $unit = new GroundingUnit('unit-1', 'Unit content');
        self::assertSame(['id' => 'unit-1', 'content' => 'Unit content'], $unit->toArray());

        $report = new GroundingReport(
            'target',
            false,
            true,
            [$claim],
            ['violation'],
            ['violation'],
            [],
            ['unit-1', 'unit-2'],
            ['unit-1'],
            ['unit-2'],
        );
        self::assertSame([
            'artifact_key' => 'target',
            'passed' => false,
            'model_passed' => true,
            'claims' => [$claim->toArray()],
            'violations' => ['violation'],
            'protocol_violations' => ['violation'],
            'content_violations' => [],
            'expected_unit_ids' => ['unit-1', 'unit-2'],
            'covered_unit_ids' => ['unit-1'],
            'missing_unit_ids' => ['unit-2'],
        ], $report->toArray());
    }

    public function test_segmenter_splits_lines_sentences_unicode_and_exact_character_boundaries(): void
    {
        $content = "  First sentence. Second question?\n\n".str_repeat('ą', 65).'… Final!  ';
        $units = (new GroundingSegmenter(64))->units($this->artifact('target', $content));

        self::assertSame([
            ['id' => 'unit-00001', 'content' => 'First sentence.'],
            ['id' => 'unit-00002', 'content' => 'Second question?'],
            ['id' => 'unit-00003', 'content' => str_repeat('ą', 64)],
            ['id' => 'unit-00004', 'content' => 'ą…'],
            ['id' => 'unit-00005', 'content' => 'Final!'],
        ], array_map(
            static fn (GroundingUnit $unit): array => $unit->toArray(),
            $units,
        ));
        self::assertSame([
            ['id' => 'unit-00001', 'content' => ''],
        ], array_map(
            static fn (GroundingUnit $unit): array => $unit->toArray(),
            (new GroundingSegmenter)->units($this->artifact('blank', " \n\t ")),
        ));
    }

    public function test_segmenter_rejects_a_unit_limit_below_sixty_four_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Grounding units must allow at least 64 characters.');

        new GroundingSegmenter(63);
    }

    public function test_exact_verifier_accepts_supported_and_no_claim_units_and_preserves_model_failure(): void
    {
        $target = $this->artifact('target', 'Short. Supported claim lives here.');
        $evidence = $this->artifact('source', 'Exact evidence quotation is available.');
        $claims = [
            new GroundingClaim(
                'unit-b',
                'Supported claim',
                'Supported claim lives here.',
                ' SUPPORTED ',
                [new GroundingEvidence(' source ', 'Exact evidence quotation')],
            ),
            new GroundingClaim(' unit-a ', '', ' Short. ', ' NO_CLAIMS ', []),
        ];

        $passed = (new ExactQuoteVerifier)->verify(
            $target,
            ['source' => $evidence],
            true,
            $claims,
            10,
            ['unit-b' => 'Supported claim lives here.', 'unit-a' => 'Short.'],
        );

        self::assertTrue($passed->passed);
        self::assertTrue($passed->modelPassed);
        self::assertSame([], $passed->violations);
        self::assertSame([], $passed->protocolViolations);
        self::assertSame([], $passed->contentViolations);
        self::assertSame(['unit-b', 'unit-a'], $passed->expectedUnitIds);
        self::assertSame(['unit-a', 'unit-b'], $passed->coveredUnitIds);
        self::assertSame([], $passed->missingUnitIds);
        self::assertSame($claims, $passed->claims);
        self::assertSame('target', $passed->artifactKey);

        $modelFailed = (new ExactQuoteVerifier)->verify(
            $target,
            ['source' => $evidence],
            false,
            $claims,
            10,
            ['unit-b' => 'Supported claim lives here.', 'unit-a' => 'Short.'],
        );
        self::assertFalse($modelFailed->passed);
        self::assertFalse($modelFailed->modelPassed);
        self::assertSame([], $modelFailed->violations);
    }

    public function test_exact_verifier_reports_every_structural_and_quote_violation_in_order(): void
    {
        $report = (new ExactQuoteVerifier)->verify(
            $this->artifact('target', 'Alpha quote is present. Unit two source.'),
            ['source' => $this->artifact('source', 'A valid evidence quotation is available.')],
            true,
            [
                new GroundingClaim(
                    'unit-1',
                    'Unsupported claim',
                    'Alpha quote is present.',
                    'unsupported',
                    [],
                ),
                new GroundingClaim(
                    'unit-1',
                    'Duplicate claim',
                    'Alpha quote is present.',
                    'no_claims',
                    [],
                ),
                new GroundingClaim(
                    '',
                    '',
                    'tiny',
                    'invented',
                    [new GroundingEvidence('missing', 'fabricated evidence quotation')],
                ),
                new GroundingClaim(
                    'unit-2',
                    'Wrong unit quote',
                    'Alpha quote is present.',
                    'supported',
                    [new GroundingEvidence('source', 'short')],
                ),
            ],
            10,
            ['unit-1' => 'Alpha quote is present.', 'unit-2' => 'Unit two source.'],
        );

        self::assertFalse($report->passed);
        self::assertSame(['unit-1', 'unit-2'], $report->coveredUnitIds);
        self::assertSame([], $report->missingUnitIds);
        self::assertSame([
            'Claim [Unsupported claim] is unsupported; only supported or no_claims units may pass.',
            'Grounding unit [unit-1] was reported more than once.',
            'Claim [#2] has an unknown or missing unit_id [].',
            'Claim [#2] has no exact source_quote in artifact [target].',
            'Claim [#2] is invented; only supported or no_claims units may pass.',
            'Claim [#2] cites unavailable artifact [missing].',
            'Claim [Wrong unit quote] source_quote does not belong to grounding unit [unit-2].',
            'Claim [Wrong unit quote] has a fabricated or too-short quote for artifact [source].',
        ], $report->violations);
        self::assertSame([
            'Grounding unit [unit-1] was reported more than once.',
            'Claim [#2] has an unknown or missing unit_id [].',
            'Claim [#2] has no exact source_quote in artifact [target].',
            'Claim [#2] is invented; only supported or no_claims units may pass.',
            'Claim [#2] cites unavailable artifact [missing].',
            'Claim [Wrong unit quote] source_quote does not belong to grounding unit [unit-2].',
            'Claim [Wrong unit quote] has a fabricated or too-short quote for artifact [source].',
        ], $report->protocolViolations);
        self::assertSame([
            'Claim [Unsupported claim] is unsupported; only supported or no_claims units may pass.',
        ], $report->contentViolations);
    }

    public function test_exact_verifier_rejects_an_empty_claim_set_and_lists_each_missing_unit(): void
    {
        $report = (new ExactQuoteVerifier)->verify(
            $this->artifact('target', 'Target'),
            [],
            true,
            [],
            1,
            ['unit-b' => 'B', 'unit-a' => 'A'],
        );

        self::assertFalse($report->passed);
        self::assertSame([], $report->coveredUnitIds);
        self::assertSame(['unit-b', 'unit-a'], $report->missingUnitIds);
        self::assertSame([
            'The verifier returned no claims; coverage cannot be established.',
            'Grounding unit [unit-b] was omitted by the verifier.',
            'Grounding unit [unit-a] was omitted by the verifier.',
        ], $report->violations);
    }

    public function test_exact_verifier_is_invariant_to_caption_whitespace(): void
    {
        $target = $this->artifact(
            'target',
            "The first issue was released 7 years\nago, in 2019\nand then came the hate.",
        );
        $evidence = $this->artifact(
            'source',
            "Let me remind you that the first issue\n was  released  7\nyears ago, in 2019.",
        );

        $report = (new ExactQuoteVerifier)->verify(
            $target,
            ['source' => $evidence],
            true,
            [
                new GroundingClaim(
                    'unit-a',
                    'Released 7 years ago',
                    'The first issue was released 7 years ago, in 2019',
                    'supported',
                    [new GroundingEvidence('source', 'released 7 years ago, in 2019')],
                ),
            ],
            10,
            ['unit-a' => $target->content],
        );

        self::assertTrue($report->passed);
        self::assertSame([], $report->violations);
    }

    public function test_exact_verifier_still_rejects_a_paraphrased_quote(): void
    {
        $target = $this->artifact('target', 'He lost his fortune overnight.');
        $evidence = $this->artifact('source', 'He lost everything he owned in a single deal.');

        $report = (new ExactQuoteVerifier)->verify(
            $target,
            ['source' => $evidence],
            true,
            [
                new GroundingClaim(
                    'unit-a',
                    'Lost fortune',
                    'He lost his fortune overnight.',
                    'supported',
                    [new GroundingEvidence('source', 'He lost all his wealth overnight.')],
                ),
            ],
            10,
            ['unit-a' => $target->content],
        );

        self::assertFalse($report->passed);
        self::assertCount(1, $report->violations);
        self::assertStringContainsString('fabricated or too-short quote', $report->violations[0]);
    }

    private function artifact(string $key, string $content): Artifact
    {
        return new Artifact($key, ArtifactType::fromString('text'), $content);
    }
}
