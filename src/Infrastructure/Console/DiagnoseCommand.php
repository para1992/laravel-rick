<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Illuminate\Console\Command;
use Rick\Laravel\Infrastructure\Persistence\SqliteQueueProfile;

final class DiagnoseCommand extends Command
{
    protected $signature = 'rick:diagnose {--strict : Return a failure status when warnings are present}';

    protected $description = 'Check Rick persistence and queue compatibility';

    public function handle(SqliteQueueProfile $sqlite): int
    {
        $warnings = $sqlite->warnings();
        if ($warnings === []) {
            $this->info('Rick persistence diagnostics passed.');

            return self::SUCCESS;
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return $this->option('strict') === true ? self::FAILURE : self::SUCCESS;
    }
}
