<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Workflow;

use InvalidArgumentException;

final readonly class OperationCall
{
    /**
     * @param  list<string>  $inputKeys
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $id,
        public string $operationId,
        public ?string $operationVersion,
        public array $inputKeys,
        public string $outputKey,
        public array $parameters = [],
    ) {
        foreach ([$id, $operationId, $outputKey] as $value) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $value) !== 1) {
                throw new InvalidArgumentException("Invalid operation call value [{$value}].");
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'operation_id' => $this->operationId,
            'operation_version' => $this->operationVersion,
            'input_keys' => $this->inputKeys,
            'output_key' => $this->outputKey,
            'parameters' => $this->parameters,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $keys = self::strings($payload['input_keys'] ?? null, 'input_keys');
        $id = self::string($payload, 'id');
        $operationId = self::string($payload, 'operation_id');
        $version = $payload['operation_version'] ?? null;
        $outputKey = self::string($payload, 'output_key');
        $parameters = self::map($payload['parameters'] ?? [], 'parameters');
        if ($version !== null && ! is_string($version)) {
            throw new InvalidArgumentException('Operation version must be a string or null.');
        }

        return new self(
            $id,
            $operationId,
            $version,
            $keys,
            $outputKey,
            $parameters,
        );
    }

    /** @param array<string, mixed> $payload */
    private static function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException("Operation field [{$key}] must be a string.");
        }

        return $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Operation field [{$key}] must be an array.");
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("Operation field [{$key}] must contain strings.");
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Operation field [{$key}] must be an object.");
        }
        $map = [];
        foreach ($value as $name => $item) {
            if (! is_string($name)) {
                throw new InvalidArgumentException("Operation field [{$key}] must be an object.");
            }
            $map[$name] = $item;
        }

        return $map;
    }
}
