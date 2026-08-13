<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Interface;

use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;

interface ExecutionConfigurationBase
{
    public function invocationLeaseSeconds(): int;

    public function maxSafeAttempts(): int;

    public function maxPromptCharacters(): int;

    public function structuredResponseAttempts(): int;

    public function structuredResponseStrategy(): string;

    public function maxInFlightInvocations(): int;

    public function groundedVerificationBatchSize(): int;

    /**
     * @return array<string, non-empty-list<
     *     array{id: string, type: 'minimum_characters', minimum: int}
     *     |array{id: string, type: 'regex', pattern: string, must_match: bool, description: string}
     * >>
     */
    public function qualityRuleSets(): array;

    /** @return array<string, class-string<StepStrategyBase>> */
    public function strategies(): array;
}
