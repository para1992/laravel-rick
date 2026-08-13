<?php

declare(strict_types=1);

namespace Rick\Stand\Fixture;

use InvalidArgumentException;
use Rick\Stand\Support\StrictJson;

final class CassetteLoader
{
    public function load(string $path): Cassette
    {
        $value = StrictJson::file($path);
        StrictJson::keys(
            $value,
            ['schema_version', 'id', 'kind', 'provenance', 'matcher', 'outcome', 'metrics'],
            'cassette',
        );
        if (($value['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported cassette schema version.');
        }

        $id = StrictJson::string($value['id'] ?? null, 'cassette.id');
        $kind = StrictJson::string($value['kind'] ?? null, 'cassette.kind');
        if (! in_array($kind, ['live_sanitized', 'synthetic'], true)) {
            throw new InvalidArgumentException("Cassette [{$id}] has an invalid kind.");
        }
        $provenance = StrictJson::object($value['provenance'] ?? null, 'cassette.provenance');
        StrictJson::keys(
            $provenance,
            ['source', 'recorded_at', 'provider', 'model', 'sanitized_by', 'source_sha256'],
            'cassette.provenance',
        );
        foreach (['source', 'recorded_at', 'provider', 'model', 'sanitized_by', 'source_sha256'] as $field) {
            StrictJson::string($provenance[$field] ?? null, "cassette.provenance.{$field}");
        }

        $matcher = StrictJson::object($value['matcher'] ?? null, 'cassette.matcher');
        StrictJson::keys($matcher, ['purpose', 'response_contract', 'prompt_contains', 'metadata'], 'cassette.matcher');
        if (isset($matcher['metadata'])) {
            $metadata = StrictJson::object($matcher['metadata'], 'cassette.matcher.metadata');
            foreach ($metadata as $key => $item) {
                if (! is_scalar($item) && $item !== null) {
                    throw new InvalidArgumentException("cassette.matcher.metadata.{$key} must be scalar.");
                }
            }
        }

        $outcome = StrictJson::object($value['outcome'] ?? null, 'cassette.outcome');
        StrictJson::keys($outcome, ['type', 'response', 'error'], 'cassette.outcome');
        $type = StrictJson::string($outcome['type'] ?? null, 'cassette.outcome.type');
        if ($type === 'response') {
            if (array_key_exists('error', $outcome)) {
                throw new InvalidArgumentException('Response cassette must not define an error.');
            }
            $response = StrictJson::object($outcome['response'] ?? null, 'cassette.outcome.response');
            StrictJson::keys($response, ['text', 'structured', 'provider', 'model'], 'cassette.outcome.response');
            foreach (['text', 'provider', 'model'] as $field) {
                if (! is_string($response[$field] ?? null)) {
                    throw new InvalidArgumentException("cassette.outcome.response.{$field} must be a string.");
                }
            }
            if (($response['structured'] ?? null) !== null) {
                StrictJson::object($response['structured'], 'cassette.outcome.response.structured');
            }
        } elseif ($type === 'provider_error') {
            if (array_key_exists('response', $outcome)) {
                throw new InvalidArgumentException('Provider-error cassette must not define a response.');
            }
            $error = StrictJson::object($outcome['error'] ?? null, 'cassette.outcome.error');
            StrictJson::keys(
                $error,
                ['safe_code', 'safe_message', 'retryable', 'request_outcome', 'request_id', 'http_status_class'],
                'cassette.outcome.error',
            );
            foreach (['safe_code', 'safe_message', 'request_outcome'] as $field) {
                StrictJson::string($error[$field] ?? null, "cassette.outcome.error.{$field}");
            }
            if (! is_bool($error['retryable'] ?? null)) {
                throw new InvalidArgumentException('cassette.outcome.error.retryable must be boolean.');
            }
        } else {
            throw new InvalidArgumentException("Cassette [{$id}] has an unknown outcome type.");
        }

        $metrics = StrictJson::object($value['metrics'] ?? null, 'cassette.metrics');
        StrictJson::keys(
            $metrics,
            ['input_tokens', 'output_tokens', 'cached_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens', 'latency_milliseconds', 'cost_usd', 'provider_requests', 'usage_complete', 'usage_present'],
            'cassette.metrics',
        );
        foreach (['input_tokens', 'output_tokens', 'cached_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens', 'latency_milliseconds', 'provider_requests'] as $field) {
            if (! is_int($metrics[$field] ?? null) || $metrics[$field] < 0) {
                throw new InvalidArgumentException("cassette.metrics.{$field} must be a non-negative integer.");
            }
        }
        if (($metrics['provider_requests'] ?? 0) < 1) {
            throw new InvalidArgumentException('cassette.metrics.provider_requests must be positive.');
        }
        foreach (['usage_complete', 'usage_present'] as $field) {
            if (! is_bool($metrics[$field] ?? null)) {
                throw new InvalidArgumentException("cassette.metrics.{$field} must be boolean.");
            }
        }
        if (! is_string($metrics['cost_usd'] ?? null) || ! is_numeric($metrics['cost_usd'])) {
            throw new InvalidArgumentException('cassette.metrics.cost_usd must be a decimal string.');
        }

        $this->assertSafe($value, 'cassette');

        return new Cassette($id, $kind, $matcher, $outcome, $metrics);
    }

    private function assertSafe(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $name = strtolower((string) $key);
                if (preg_match('/^(authorization|cookie|set-cookie|api[_-]?key|access[_-]?token|secret|headers?|raw[_-]?body)$/', $name) === 1) {
                    throw new InvalidArgumentException("Cassette contains forbidden sensitive field [{$path}.{$key}].");
                }
                $this->assertSafe($item, $path.'.'.$key);
            }

            return;
        }
        if (is_string($value) && preg_match('/(?:Bearer\s+[A-Za-z0-9._-]+|sk-[A-Za-z0-9_-]{12,}|AIza[A-Za-z0-9_-]{20,})/i', $value) === 1) {
            throw new InvalidArgumentException("Cassette contains a value resembling a credential at [{$path}].");
        }
    }
}
