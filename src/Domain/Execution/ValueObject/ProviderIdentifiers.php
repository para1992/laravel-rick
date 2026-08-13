<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Execution\ValueObject;

use InvalidArgumentException;

final readonly class ProviderIdentifiers
{
    public function __construct(
        public ?string $gatewayInvocationId,
        public ?string $providerRequestId,
        public ?string $providerGenerationId,
        public ProviderIdSource $source,
    ) {
        foreach ([
            'gateway invocation' => $gatewayInvocationId,
            'provider request' => $providerRequestId,
            'provider generation' => $providerGenerationId,
        ] as $name => $value) {
            if (
                $value !== null
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/+=-]{0,127}$/D', $value) !== 1
            ) {
                throw new InvalidArgumentException("{$name} ID is invalid.");
            }
        }
        if (
            $source === ProviderIdSource::Unavailable
            && ($providerRequestId !== null || $providerGenerationId !== null)
        ) {
            throw new InvalidArgumentException('Unavailable provider IDs cannot contain provider identifiers.');
        }
    }

    public static function unavailable(?string $gatewayInvocationId = null): self
    {
        return new self($gatewayInvocationId, null, null, ProviderIdSource::Unavailable);
    }

    /** @param array<string, mixed> $metadata */
    public static function fromMetadata(array $metadata): self
    {
        $requestId = self::identifier($metadata['provider_request_id'] ?? $metadata['request_id'] ?? null);
        $generationId = self::identifier($metadata['provider_generation_id'] ?? null);
        $source = $metadata['provider_id_source'] ?? null;
        $source = is_string($source) ? ProviderIdSource::tryFrom($source) : null;
        if ($requestId === null && $generationId === null) {
            $source = ProviderIdSource::Unavailable;
        } elseif ($source === null || $source === ProviderIdSource::Unavailable) {
            $source = ProviderIdSource::Sdk;
        }

        return new self(
            self::identifier($metadata['gateway_invocation_id'] ?? $metadata['invocation_id'] ?? null),
            $requestId,
            $generationId,
            $source,
        );
    }

    private static function identifier(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/+=-]{0,127}$/D', $value) === 1
            ? $value
            : null;
    }
}
