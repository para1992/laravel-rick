<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Rick\Laravel\Application\Interface\TenantCatalogBase;
use Rick\Laravel\Application\Interface\TenantContextBase;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Rick;

final class InspectRunCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'rick:run {run} {--tenant=*} {--all}';

    protected $description = 'Inspect a Rick run and its measured resource usage';

    public function handle(
        Rick $rick,
        TenantCatalogBase $catalog,
        TenantContextBase $tenant,
    ): int {
        $id = RunId::fromString($this->string($this->argument('run'), 'run'));

        return $this->runForTenants($catalog, $tenant, function (string $tenantId) use ($rick, $id): void {
            $snapshot = $rick->snapshot($id);
            $this->line(json_encode([
                'tenant_id' => $tenantId,
                'id' => $snapshot->id->toString(),
                'status' => $snapshot->status->value,
                'version' => $snapshot->version,
                'calls_used' => $snapshot->callsUsed,
                'output' => $snapshot->output(),
                'metrics' => $rick->metrics($id),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        });
    }

    private function string(mixed $value, string $name): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Console input [{$name}] must be a non-empty string.");
        }

        return $value;
    }
}
