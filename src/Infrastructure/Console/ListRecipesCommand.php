<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Illuminate\Console\Command;
use Rick\Laravel\Application\Compilation\Support\Recipe\RecipeRegistry;

final class ListRecipesCommand extends Command
{
    protected $signature = 'rick:recipes';

    protected $description = 'List registered Rick workflow recipes';

    public function handle(RecipeRegistry $recipes): int
    {
        foreach ($recipes->ids() as $id) {
            $this->line($id);
        }

        return self::SUCCESS;
    }
}
