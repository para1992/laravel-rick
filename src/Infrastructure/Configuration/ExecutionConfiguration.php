<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

use InvalidArgumentException;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;

final readonly class ExecutionConfiguration
{
    /** @param array<string, class-string<StepStrategyBase>> $strategies */
    public function __construct(
        public int $invocationLeaseSeconds,
        public int $maxSafeAttempts,
        public int $maxInFlightInvocations,
        public int $groundedVerificationBatchSize,
        public int $recoveryBatchSize,
        public bool $recoveryScheduleEnabled,
        public array $strategies,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        ConfigurationInput::keys($input, [
            'invocation_lease_seconds',
            'max_safe_attempts',
            'max_in_flight_invocations',
            'grounded_verification_batch_size',
            'recovery_batch_size',
            'recovery_schedule_enabled',
            'strategies',
        ], 'execution');
        $configured = ConfigurationInput::map($input['strategies'] ?? null, 'execution.strategies');
        $expected = [
            'resolve', 'raw_prompt', 'define_dod', 'context', 'generate', 'unfold', 'judge',
            'edit', 'output_glue', 'operation', 'quality_gate', 'grounded_verify', 'parallel',
            'map', 'join', 'branch', 'wait_for_input', 'await_human', 'application', 'agent',
        ];
        ConfigurationInput::keys($configured, $expected, 'execution.strategies');
        if (array_keys($configured) !== $expected) {
            throw new InvalidArgumentException(
                'Rick execution strategies must be explicitly configured in canonical StepType order.',
            );
        }

        $strategies = [];
        foreach ($configured as $type => $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_a($class, StepStrategyBase::class, true)) {
                throw new InvalidArgumentException(
                    "Rick execution strategy [{$type}] must implement StepStrategyBase.",
                );
            }
            $strategies[$type] = $class;
        }

        return new self(
            ConfigurationInput::integer($input['invocation_lease_seconds'] ?? null, 'execution.invocation_lease_seconds', 1),
            ConfigurationInput::integer($input['max_safe_attempts'] ?? null, 'execution.max_safe_attempts', 1),
            ConfigurationInput::integer($input['max_in_flight_invocations'] ?? null, 'execution.max_in_flight_invocations', 1),
            ConfigurationInput::integer($input['grounded_verification_batch_size'] ?? null, 'execution.grounded_verification_batch_size', 1),
            ConfigurationInput::integer($input['recovery_batch_size'] ?? null, 'execution.recovery_batch_size', 1, 10000),
            ConfigurationInput::boolean($input['recovery_schedule_enabled'] ?? null, 'execution.recovery_schedule_enabled'),
            $strategies,
        );
    }
}
