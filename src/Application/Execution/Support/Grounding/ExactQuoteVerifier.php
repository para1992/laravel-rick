<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Grounding;

use Rick\Laravel\Domain\Run\Artifact;

final readonly class ExactQuoteVerifier
{
    /**
     * @param  array<string, Artifact>  $evidence
     * @param  list<GroundingClaim>  $claims
     * @param  array<string, string>  $expectedUnits
     */
    public function verify(
        Artifact $target,
        array $evidence,
        bool $modelPassed,
        array $claims,
        int $minimumQuoteCharacters,
        array $expectedUnits,
    ): GroundingReport {
        $violations = [];
        $protocolViolations = [];
        $contentViolations = [];
        $coveredUnits = [];
        if ($claims === []) {
            self::violation(
                'The verifier returned no claims; coverage cannot be established.',
                $violations,
                $protocolViolations,
            );
        }

        foreach ($claims as $index => $claim) {
            $label = trim($claim->claim) !== '' ? trim($claim->claim) : "#{$index}";
            $unitId = trim($claim->unitId);
            if (! array_key_exists($unitId, $expectedUnits)) {
                self::violation(
                    "Claim [{$label}] has an unknown or missing unit_id [{$unitId}].",
                    $violations,
                    $protocolViolations,
                );
            } elseif (isset($coveredUnits[$unitId])) {
                self::violation(
                    "Grounding unit [{$unitId}] was reported more than once.",
                    $violations,
                    $protocolViolations,
                );
            } else {
                $coveredUnits[$unitId] = true;
            }

            $sourceMinimum = isset($expectedUnits[$unitId])
                ? min($minimumQuoteCharacters, max(1, mb_strlen($expectedUnits[$unitId])))
                : $minimumQuoteCharacters;
            if (! $this->isExactQuote($target->content, $claim->sourceQuote, $sourceMinimum)) {
                self::violation(
                    "Claim [{$label}] has no exact source_quote in artifact [{$target->key}].",
                    $violations,
                    $protocolViolations,
                );
            }
            if (
                isset($expectedUnits[$unitId])
                && ! $this->isExactQuote(
                    $expectedUnits[$unitId],
                    $claim->sourceQuote,
                    $sourceMinimum,
                )
            ) {
                self::violation(
                    "Claim [{$label}] source_quote does not belong to grounding unit [{$unitId}].",
                    $violations,
                    $protocolViolations,
                );
            }

            $verdict = strtolower(trim($claim->verdict));
            if ($verdict === 'unsupported') {
                self::violation(
                    "Claim [{$label}] is {$verdict}; only supported or no_claims units may pass.",
                    $violations,
                    $contentViolations,
                );
            } elseif (! in_array($verdict, ['supported', 'no_claims'], true)) {
                self::violation(
                    "Claim [{$label}] is {$verdict}; only supported or no_claims units may pass.",
                    $violations,
                    $protocolViolations,
                );
            }
            if ($verdict === 'no_claims') {
                continue;
            }
            if ($verdict === 'supported' && $claim->evidence === []) {
                self::violation(
                    "Claim [{$label}] has no evidence references.",
                    $violations,
                    $protocolViolations,
                );

                continue;
            }
            foreach ($claim->evidence as $reference) {
                $artifact = $evidence[trim($reference->artifactKey)] ?? null;
                if ($artifact === null) {
                    self::violation(
                        "Claim [{$label}] cites unavailable artifact [{$reference->artifactKey}].",
                        $violations,
                        $protocolViolations,
                    );

                    continue;
                }
                if (! $this->isExactQuote(
                    $artifact->content,
                    $reference->quote,
                    $minimumQuoteCharacters,
                )) {
                    self::violation(
                        "Claim [{$label}] has a fabricated or too-short quote"
                            ." for artifact [{$reference->artifactKey}].",
                        $violations,
                        $protocolViolations,
                    );
                }
            }
        }

        $expectedUnitIds = array_keys($expectedUnits);
        $coveredUnitIds = array_keys($coveredUnits);
        $missingUnitIds = array_values(array_diff($expectedUnitIds, $coveredUnitIds));
        foreach ($missingUnitIds as $unitId) {
            self::violation(
                "Grounding unit [{$unitId}] was omitted by the verifier.",
                $violations,
                $protocolViolations,
            );
        }
        sort($coveredUnitIds);

        return new GroundingReport(
            $target->key,
            $violations === [] && $modelPassed,
            $modelPassed,
            $claims,
            $violations,
            $protocolViolations,
            $contentViolations,
            $expectedUnitIds,
            $coveredUnitIds,
            $missingUnitIds,
        );
    }

    /**
     * @param  list<string>  $violations
     * @param  list<string>  $category
     */
    private static function violation(string $message, array &$violations, array &$category): void
    {
        $violations[] = $message;
        $category[] = $message;
    }

    private function isExactQuote(string $haystack, string $quote, int $minimumCharacters): bool
    {
        $quote = trim($quote);

        if (mb_strlen($quote) < $minimumCharacters) {
            return false;
        }

        // Auto-captions carry line breaks and repeated spaces inside
        // sentences; the quote must still match verbatim once whitespace
        // runs are collapsed to a single space on both sides.
        return str_contains(
            $this->collapseWhitespace($haystack),
            $this->collapseWhitespace($quote),
        );
    }

    private function collapseWhitespace(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    }
}
