<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Configuration;

use InvalidArgumentException;
use Rick\Laravel\Application\Compilation\Interface\StepCodecBase;

final readonly class PersistenceConfiguration
{
    /**
     * @param  array{runs: string, step_executions: string, llm_invocations: string, invocation_attempts: string, outbox: string, observations: string}  $tables
     * @param  array<string, class-string<StepCodecBase>>  $stepCodecs
     * @param  array{runs: string, step_executions: string, llm_invocations: string}  $legacySource
     */
    public function __construct(
        public array $tables,
        public array $stepCodecs,
        public array $legacySource,
    ) {}

    /**
     * @param  array<string, mixed>  $tablesInput
     * @param  array<string, mixed>  $persistenceInput
     * @param  array<string, mixed>  $legacyInput
     */
    public static function from(array $tablesInput, array $persistenceInput, array $legacyInput): self
    {
        $tableKeys = [
            'runs',
            'step_executions',
            'llm_invocations',
            'invocation_attempts',
            'outbox',
            'observations',
        ];
        ConfigurationInput::keys($tablesInput, $tableKeys, 'tables');
        $tables = [
            'runs' => ConfigurationInput::table($tablesInput['runs'] ?? null, 'tables.runs'),
            'step_executions' => ConfigurationInput::table(
                $tablesInput['step_executions'] ?? null,
                'tables.step_executions',
            ),
            'llm_invocations' => ConfigurationInput::table(
                $tablesInput['llm_invocations'] ?? null,
                'tables.llm_invocations',
            ),
            'invocation_attempts' => ConfigurationInput::table(
                $tablesInput['invocation_attempts'] ?? null,
                'tables.invocation_attempts',
            ),
            'outbox' => ConfigurationInput::table($tablesInput['outbox'] ?? null, 'tables.outbox'),
            'observations' => ConfigurationInput::table(
                $tablesInput['observations'] ?? 'rick_run_observations',
                'tables.observations',
            ),
        ];
        if (count(array_unique($tables)) !== count($tables)) {
            throw new InvalidArgumentException('Rick persistence table names must be unique.');
        }

        ConfigurationInput::keys($persistenceInput, ['step_codecs'], 'persistence');
        $configuredCodecs = ConfigurationInput::map(
            $persistenceInput['step_codecs'] ?? null,
            'persistence.step_codecs',
        );
        $codecs = [];
        foreach ($configuredCodecs as $type => $class) {
            if (! is_string($class) || ! class_exists($class)
                || ! is_a($class, StepCodecBase::class, true)) {
                throw new InvalidArgumentException('Custom step codecs must map step type to StepCodecBase class.');
            }
            $codecs[$type] = $class;
        }

        ConfigurationInput::keys($legacyInput, ['source'], 'legacy_migration');
        $sourceInput = ConfigurationInput::map($legacyInput['source'] ?? null, 'legacy_migration.source');
        $sourceKeys = ['runs', 'step_executions', 'llm_invocations'];
        ConfigurationInput::keys($sourceInput, $sourceKeys, 'legacy_migration.source');
        $source = [
            'runs' => ConfigurationInput::table(
                $sourceInput['runs'] ?? null,
                'legacy_migration.source.runs',
            ),
            'step_executions' => ConfigurationInput::table(
                $sourceInput['step_executions'] ?? null,
                'legacy_migration.source.step_executions',
            ),
            'llm_invocations' => ConfigurationInput::table(
                $sourceInput['llm_invocations'] ?? null,
                'legacy_migration.source.llm_invocations',
            ),
        ];

        return new self($tables, $codecs, $source);
    }
}
