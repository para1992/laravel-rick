<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

use Rick\Laravel\Application\Interface\ExecutionConfigurationBase;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;

final readonly class RickConfiguration implements ExecutionConfigurationBase
{
    public function __construct(
        public QueueConfiguration $queue,
        public ExecutionConfiguration $execution,
        public OutboxConfiguration $outbox,
        public TenantConfiguration $tenant,
        public PersistenceConfiguration $persistence,
        public LlmConfiguration $llm,
        public QualityConfiguration $quality,
        public RetentionConfiguration $retention,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input, JsonSchemaValidatorBase $schemas): self
    {
        ConfigurationInput::keys($input, [
            'tables', 'tenant', 'queue', 'execution', 'outbox', 'retention',
            'persistence', 'legacy_migration', 'quality', 'llm',
        ], 'rick');

        $quality = QualityConfiguration::from(
            ConfigurationInput::map($input['quality'] ?? null, 'quality'),
        );

        return new self(
            QueueConfiguration::from(ConfigurationInput::map($input['queue'] ?? null, 'queue')),
            ExecutionConfiguration::from(ConfigurationInput::map($input['execution'] ?? null, 'execution')),
            OutboxConfiguration::from(ConfigurationInput::map($input['outbox'] ?? null, 'outbox')),
            TenantConfiguration::from(ConfigurationInput::map($input['tenant'] ?? null, 'tenant')),
            PersistenceConfiguration::from(
                ConfigurationInput::map($input['tables'] ?? null, 'tables'),
                ConfigurationInput::map($input['persistence'] ?? null, 'persistence'),
                ConfigurationInput::map($input['legacy_migration'] ?? null, 'legacy_migration'),
            ),
            LlmConfiguration::from(
                ConfigurationInput::map($input['llm'] ?? null, 'llm'),
                array_keys($quality->ruleSets),
                $schemas,
            ),
            $quality,
            RetentionConfiguration::from(
                ConfigurationInput::map($input['retention'] ?? null, 'retention'),
            ),
        );
    }

    public function invocationLeaseSeconds(): int
    {
        return $this->execution->invocationLeaseSeconds;
    }

    public function maxSafeAttempts(): int
    {
        return $this->execution->maxSafeAttempts;
    }

    public function maxPromptCharacters(): int
    {
        return $this->llm->maxPromptCharacters;
    }

    public function structuredResponseAttempts(): int
    {
        return $this->llm->structuredResponseAttempts;
    }

    public function structuredResponseStrategy(): string
    {
        return $this->llm->structuredResponseStrategy;
    }

    public function maxInFlightInvocations(): int
    {
        return $this->execution->maxInFlightInvocations;
    }

    public function groundedVerificationBatchSize(): int
    {
        return $this->execution->groundedVerificationBatchSize;
    }

    public function qualityRuleSets(): array
    {
        return $this->quality->ruleSets;
    }

    public function strategies(): array
    {
        return $this->execution->strategies;
    }
}
