<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality;

use InvalidArgumentException;

final readonly class ContentDistinctness
{
    private const int SHINGLE_WORDS = 5;

    private const int SIGNATURE_SIZE = 96;

    private const int MINIMUM_WORDS = 20;

    private const int MINIMUM_SHINGLES = 12;

    private const float SIMILARITY_THRESHOLD = 0.80;

    /** @return array{algorithm: string, content_hash: string, word_count: int, shingle_hashes: list<string>} */
    public function signature(string $content): array
    {
        $normalized = self::normalize($content);
        $words = $normalized === '' ? [] : explode(' ', $normalized);
        $shingles = [];
        $maximum = count($words) - self::SHINGLE_WORDS;
        for ($index = 0; $index <= $maximum; $index++) {
            $shingles[] = substr(hash(
                'sha256',
                implode(' ', array_slice($words, $index, self::SHINGLE_WORDS)),
            ), 0, 16);
        }
        $shingles = array_values(array_unique($shingles));
        sort($shingles);

        return [
            'algorithm' => 'normalized_sha256_bottom_k_5gram_v1',
            'content_hash' => hash('sha256', $normalized),
            'word_count' => count($words),
            'shingle_hashes' => array_slice($shingles, 0, self::SIGNATURE_SIZE),
        ];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return 'exact_duplicate'|'high_similarity'|null
     */
    public function violation(string $content, array $policy): ?string
    {
        $prior = $policy['prior_signatures'] ?? null;
        if (! is_array($prior) || ! array_is_list($prior)) {
            throw new InvalidArgumentException(
                'Content distinctness policy requires a list of prior signatures.',
            );
        }
        $candidate = $this->signature($content);
        foreach ($prior as $signature) {
            if (! is_array($signature)) {
                throw new InvalidArgumentException('Content distinctness signature must be an object.');
            }
            $hash = $signature['content_hash'] ?? null;
            if (is_string($hash) && hash_equals($hash, $candidate['content_hash'])) {
                return 'exact_duplicate';
            }
            $wordCount = $signature['word_count'] ?? null;
            $shingles = $signature['shingle_hashes'] ?? null;
            if (
                ! is_int($wordCount)
                || ! is_array($shingles)
                || ! array_is_list($shingles)
                || min($wordCount, $candidate['word_count']) < self::MINIMUM_WORDS
            ) {
                continue;
            }
            $shingles = array_values(array_filter(
                $shingles,
                static fn (mixed $hash): bool => is_string($hash),
            ));
            $denominator = min(count($shingles), count($candidate['shingle_hashes']));
            if ($denominator < self::MINIMUM_SHINGLES) {
                continue;
            }
            $intersection = count(array_intersect($shingles, $candidate['shingle_hashes']));
            if (($intersection / $denominator) >= self::SIMILARITY_THRESHOLD) {
                return 'high_similarity';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $sourceSignature */
    public function restates(string $content, array $sourceSignature): bool
    {
        $candidate = $this->signature($content);
        $hash = $sourceSignature['content_hash'] ?? null;
        if (is_string($hash) && hash_equals($hash, $candidate['content_hash'])) {
            return true;
        }
        $sourceWords = $sourceSignature['word_count'] ?? null;
        $sourceShingles = $sourceSignature['shingle_hashes'] ?? null;
        if (! is_int($sourceWords) || ! is_array($sourceShingles) || ! array_is_list($sourceShingles)) {
            throw new InvalidArgumentException('Source content signature is invalid.');
        }
        if ($candidate['word_count'] > max($sourceWords * 2, $sourceWords + 20)) {
            return false;
        }
        $sourceShingles = array_values(array_filter(
            $sourceShingles,
            static fn (mixed $value): bool => is_string($value),
        ));
        $denominator = count($candidate['shingle_hashes']);
        if ($denominator < 4) {
            return false;
        }

        return count(array_intersect(
            $sourceShingles,
            $candidate['shingle_hashes'],
        )) / $denominator >= self::SIMILARITY_THRESHOLD;
    }

    private static function normalize(string $content): string
    {
        $normalized = mb_strtolower(trim($content));
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);

        return trim(is_string($normalized) ? $normalized : '');
    }
}
