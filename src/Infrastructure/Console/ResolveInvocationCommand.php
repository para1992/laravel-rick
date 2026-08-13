<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Infrastructure\Recovery\InvocationRecoveryRunner;

final class ResolveInvocationCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'rick:invocation:resolve
        {invocation : Invocation ID}
        {outcome : retry or fail}
        {--tenant=*}
        {--all}
        {--message=Operator reconciled the provider outcome.}';

    protected $description = 'Resolve an indeterminate paid invocation after provider reconciliation';

    public function handle(
        InvocationRecoveryRunner $recovery,
        TenantCatalogBase $catalog,
        TenantContextBase $tenant,
    ): int {
        $id = InvocationId::fromString(
            $this->string($this->argument('invocation'), 'invocation'),
        );
        $outcome = $this->string($this->argument('outcome'), 'outcome');
        $message = $this->string($this->option('message'), 'message');

        return $this->runForTenants(
            $catalog,
            $tenant,
            function (string $tenantId) use ($recovery, $id, $outcome, $message): void {
                $recovery->resolve(
                    $id,
                    $outcome,
                    $message,
                );
                $this->info("Tenant [{$tenantId}]: invocation recovery decision persisted.");
            },
        );
    }

    private function string(mixed $value, string $name): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Console input [{$name}] must be a non-empty string.");
        }

        return $value;
    }
}
