<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Rick;

final class RecoverRunCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'rick:run:recover
        {run : Failed parent run ID}
        {action : retry_failed, continue_successful, or fork_failed_step}
        {--call-limit= : Separate call limit for the recovery child}
        {--tenant=*}
        {--all}';

    protected $description = 'Create an idempotent recovery child without rewriting terminal run history';

    public function handle(
        Rick $rick,
        TenantCatalogBase $catalog,
        TenantContextBase $tenant,
    ): int {
        $parent = RunId::fromString($this->string($this->argument('run'), 'run'));
        $action = RunRecoveryAction::from($this->string($this->argument('action'), 'action'));
        $callLimit = $this->callLimit($this->option('call-limit'));

        return $this->runForTenants(
            $catalog,
            $tenant,
            function (string $tenantId) use ($rick, $parent, $action, $callLimit): void {
                $result = $rick->recover($parent, $action, $callLimit);
                $this->line(json_encode([
                    'tenant_id' => $tenantId,
                    'parent_run_id' => $parent->toString(),
                    'run_id' => $result->run->id->toString(),
                    'action' => $action->value,
                    'status' => $result->run->status->value,
                    'reused_invocations' => $result->reusedInvocations,
                    'queued_invocations' => $result->queuedInvocations,
                    'copied_failures' => $result->copiedFailures,
                    'already_exists' => $result->alreadyExists,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            },
        );
    }

    private function string(mixed $value, string $name): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Console input [{$name}] must be a non-empty string.");
        }

        return trim($value);
    }

    private function callLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (int) $value < 1 || (string) (int) $value !== (string) $value) {
            throw new InvalidArgumentException('The recovery call limit must be a positive integer.');
        }

        return (int) $value;
    }
}
