<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class MakeRickWorkflowCommand extends Command
{
    protected $signature = 'make:rick-workflow {name}';

    protected $description = 'Create a new Rick workflow class';

    public function handle(Filesystem $files): int
    {
        $class = Str::studly($this->string($this->argument('name'), 'name'));
        $path = app_path('Workflows/'.$class.'.php');

        if ($files->exists($path)) {
            $this->error("Workflow [{$class}] already exists.");

            return self::FAILURE;
        }

        $stub = $files->get(__DIR__.'/../../../stubs/workflow.stub');

        $contents = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ name }}'],
            ['App\\Workflows', $class, Str::kebab($class)],
            $stub,
        );

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);

        $this->info("Workflow [{$class}] created successfully.");

        return self::SUCCESS;
    }

    private function string(mixed $value, string $name): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Console input [{$name}] must be a non-empty string.");
        }

        return $value;
    }
}
