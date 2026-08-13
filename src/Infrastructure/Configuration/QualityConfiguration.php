<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

use InvalidArgumentException;

final readonly class QualityConfiguration
{
    /**
     * @param array<string, non-empty-list<
     *     array{id: string, type: 'minimum_characters', minimum: int}
     *     |array{id: string, type: 'regex', pattern: string, must_match: bool, description: string}
     * >> $ruleSets
     */
    public function __construct(public array $ruleSets) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        ConfigurationInput::keys($input, ['rule_sets'], 'quality');
        $setsInput = ConfigurationInput::map($input['rule_sets'] ?? null, 'quality.rule_sets');
        $sets = [];

        foreach ($setsInput as $setId => $definitions) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $setId) !== 1) {
                throw new InvalidArgumentException('Quality rule-set IDs must be stable identifiers.');
            }
            $rules = [];
            foreach (ConfigurationInput::list($definitions, "quality.rule_sets.{$setId}") as $index => $value) {
                $path = "quality.rule_sets.{$setId}.{$index}";
                $definition = ConfigurationInput::map($value, $path);
                $type = ConfigurationInput::string($definition['type'] ?? null, "{$path}.type");
                $allowed = match ($type) {
                    'minimum_characters' => ['id', 'type', 'minimum'],
                    'regex' => ['id', 'type', 'pattern', 'must_match', 'description'],
                    default => throw new InvalidArgumentException(
                        "Unsupported quality rule type [{$type}].",
                    ),
                };
                ConfigurationInput::keys($definition, $allowed, $path);
                $id = ConfigurationInput::string(
                    $definition['id'] ?? "{$setId}.{$index}",
                    "{$path}.id",
                );
                if (preg_match('/^[a-z][a-z0-9_.-]*$/', $id) !== 1) {
                    throw new InvalidArgumentException("Quality rule ID [{$id}] is invalid.");
                }

                if ($type === 'minimum_characters') {
                    $rules[] = [
                        'id' => $id,
                        'type' => $type,
                        'minimum' => ConfigurationInput::integer(
                            $definition['minimum'] ?? null,
                            "{$path}.minimum",
                            1,
                        ),
                    ];
                } else {
                    $pattern = ConfigurationInput::string($definition['pattern'] ?? null, "{$path}.pattern");
                    if (! self::validPattern($pattern)) {
                        throw new InvalidArgumentException("Quality regex at [{$path}.pattern] is invalid.");
                    }
                    $rules[] = [
                        'id' => $id,
                        'type' => $type,
                        'pattern' => $pattern,
                        'must_match' => ConfigurationInput::boolean(
                            $definition['must_match'] ?? null,
                            "{$path}.must_match",
                        ),
                        'description' => ConfigurationInput::string(
                            $definition['description'] ?? null,
                            "{$path}.description",
                        ),
                    ];
                }
            }
            if ($rules === []) {
                throw new InvalidArgumentException("Quality rule set [{$setId}] must not be empty.");
            }
            $sets[$setId] = $rules;
        }

        return new self($sets);
    }

    private static function validPattern(string $pattern): bool
    {
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
