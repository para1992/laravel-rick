<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Rick\Laravel\Application\Compilation\Support\Builder\ParallelBuilder;
use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder;
use Rick\Laravel\Infrastructure\Configuration\ConfigurationInput;
use Rick\Laravel\Rick;
use SplFileInfo;

final class ArchitectureTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function phpFiles(): iterable
    {
        $root = dirname(__DIR__, 2).'/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield str_replace($root.'/', '', $file->getPathname()) => [$file->getPathname()];
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function suitePhpFiles(): iterable
    {
        foreach ([
            [dirname(__DIR__, 2).'/tests', 'Rick\\Laravel\\Tests'],
            [dirname(__DIR__, 2).'/tests-live', 'Rick\\Laravel\\LiveTests'],
        ] as [$root, $rootNamespace]) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }
                if ($file->getExtension() !== 'php' || in_array($file->getBasename(), ['bootstrap.php', 'Pest.php'], true)) {
                    continue;
                }

                yield str_replace($root.'/', '', $file->getPathname()) => [
                    $file->getPathname(),
                    $root,
                    $rootNamespace,
                ];
            }
        }
    }

    #[DataProvider('phpFiles')]
    public function test_namespace_matches_path(string $file): void
    {
        $source = (string) file_get_contents($file);
        preg_match('/^namespace\\s+([^;]+);/m', $source, $match);
        $relative = substr($file, strlen(dirname(__DIR__, 2).'/src/'), -4);
        $directory = dirname($relative);
        $expected = $directory === '.'
            ? 'Rick\\Laravel'
            : 'Rick\\Laravel\\'.str_replace('/', '\\', $directory);

        self::assertSame($expected, $match[1] ?? null);
    }

    #[DataProvider('suitePhpFiles')]
    public function test_test_suite_namespaces_match_paths(
        string $file,
        string $root,
        string $rootNamespace,
    ): void {
        $source = (string) file_get_contents($file);
        preg_match('/^namespace\\s+([^;]+);/m', $source, $match);
        $relative = substr($file, strlen($root) + 1, -4);
        $directory = dirname($relative);
        $expected = $directory === '.'
            ? $rootNamespace
            : $rootNamespace.'\\'.str_replace('/', '\\', $directory);

        self::assertSame($expected, $match[1] ?? null);
    }

    #[DataProvider('phpFiles')]
    public function test_interfaces_live_in_interface_directories_and_end_in_base(string $file): void
    {
        $source = (string) file_get_contents($file);
        if (preg_match('/^interface\\s+(\\w+)/m', $source, $match) !== 1) {
            self::expectNotToPerformAssertions();

            return;
        }

        self::assertStringContainsString('/Interface/', $file);
        self::assertStringEndsWith('Base', $match[1]);
    }

    #[DataProvider('phpFiles')]
    public function test_layer_dependencies_and_forbidden_names(string $file): void
    {
        $source = (string) file_get_contents($file);
        $relative = str_replace(dirname(__DIR__, 2).'/src/', '', $file);

        if (str_starts_with($relative, 'Domain/')) {
            self::assertDoesNotMatchRegularExpression('/^use\\s+Illuminate\\\\/m', $source);
            self::assertDoesNotMatchRegularExpression(
                '/^use\\s+Rick\\\\Laravel\\\\(?:Application|Infrastructure)\\\\/m',
                $source,
            );
        }
        if (str_starts_with($relative, 'Application/')) {
            self::assertStringNotContainsString('use Rick\\Laravel\\Infrastructure\\', $source);
        }

        self::assertDoesNotMatchRegularExpression('/^use\\s+Rick\\\\(?!Laravel\\\\)/m', $source);
        self::assertDoesNotMatchRegularExpression(
            '/^(?:final\\s+(?:readonly\\s+)?|abstract\\s+)?class\\s+\\w+(?:Manager|Service|Helper|Util|Processor|Executor|Coordinator)\\b/m',
            $source,
        );
        self::assertDoesNotMatchRegularExpression('/\\b(?:serialize|unserialize)\\s*\\(/', $source);
    }

    public function test_only_the_empty_entry_point_is_a_concrete_handler(): void
    {
        $handlers = [];
        foreach (self::phpFiles() as [$file]) {
            $source = (string) file_get_contents($file);
            if (preg_match('/^(?:final\\s+)?class\\s+(\\w*Handler)\\b/m', $source, $match) === 1) {
                $handlers[$file] = $match[1];
            }
        }

        self::assertSame(
            [dirname(__DIR__, 2).'/src/Application/Orchestration/EntryPoint/Handler.php' => 'Handler'],
            $handlers,
        );
        $entryFile = array_key_first($handlers);
        $entry = (string) file_get_contents($entryFile);
        self::assertMatchesRegularExpression('/final\\s+class\\s+Handler\\s+extends\\s+HandlerBase\\s*\\{\\s*\\}/s', $entry);
    }

    public function test_application_modules_have_one_provider_and_gate_each(): void
    {
        $root = dirname(__DIR__, 2).'/src/Application';

        self::assertSame(
            ['Provider.php'],
            array_values(array_diff(self::entries($root.'/Compilation/Provider'), ['.', '..'])),
        );
        self::assertSame(
            ['CompilationGateContract.php'],
            array_values(array_diff(self::entries($root.'/Compilation/Contract'), ['.', '..'])),
        );
        self::assertSame(
            ['Provider.php'],
            array_values(array_diff(self::entries($root.'/Execution/Provider'), ['.', '..'])),
        );
        self::assertSame(
            ['ExecutionGateContract.php'],
            array_values(array_diff(self::entries($root.'/Execution/Contract'), ['.', '..'])),
        );
    }

    public function test_workflow_definition_builders_belong_to_compilation(): void
    {
        $application = dirname(__DIR__, 2).'/src/Application';

        self::assertDirectoryDoesNotExist($application.'/Workflow');
        self::assertSame(
            'Rick\\Laravel\\Application\\Compilation\\Support\\Builder',
            (new ReflectionClass(WorkflowBuilder::class))->getNamespaceName(),
        );
        self::assertSame(
            'Rick\\Laravel\\Application\\Compilation\\Support\\Builder',
            (new ReflectionClass(ParallelBuilder::class))->getNamespaceName(),
        );
    }

    public function test_only_agreed_application_directories_are_exposed_outside_support(): void
    {
        $application = dirname(__DIR__, 2).'/src/Application';

        self::assertSame([
            'Compilation',
            'Execution',
            'Gate',
            'Handler',
            'Interface',
            'Orchestration',
        ], self::directories($application));
        self::assertSame([
            'Contract',
            'Exception',
            'Interface',
            'Pipe',
            'Provider',
            'Strategy',
            'Support',
            'ValueObject',
        ], self::directories($application.'/Compilation'));
        self::assertSame([
            'Contract',
            'Exception',
            'Interface',
            'Pipe',
            'Provider',
            'Request',
            'Result',
            'Strategy',
            'Support',
            'ValueObject',
        ], self::directories($application.'/Execution'));
        self::assertSame([
            'Builder',
            'Compiler',
            'Recipe',
        ], self::directories($application.'/Compilation/Support'));
        self::assertSame([
            'Dispatch',
            'Event',
            'Factory',
            'Grounding',
            'Guard',
            'Interaction',
            'Llm',
            'Memory',
            'Metrics',
            'Planning',
            'Quality',
            'Recovery',
            'Reduction',
            'Registry',
            'Schema',
        ], self::directories($application.'/Execution/Support'));
        self::assertSame([
            'Exception',
            'Pipe',
        ], self::directories($application.'/Gate'));
        self::assertSame([
            'EntryPoint',
            'Exception',
            'Pipe',
        ], self::directories($application.'/Orchestration'));
    }

    public function test_parcel_is_a_framework_free_domain_value_object(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        $parcel = (string) file_get_contents($root.'/Domain/ValueObject/Parcel.php');

        self::assertSame([
            'Event',
            'Exception',
            'Execution',
            'Interface',
            'Llm',
            'Memory',
            'Metrics',
            'Run',
            'ValueObject',
            'Workflow',
        ], self::directories($root.'/Domain'));
        self::assertFileExists($root.'/Domain/Interface/ParcelItemBase.php');
        self::assertFileExists($root.'/Domain/Exception/ParcelItemNotFoundException.php');
        self::assertFileExists($root.'/Domain/Exception/ParcelItemAmbiguousException.php');
        self::assertStringNotContainsString('Illuminate\\Support\\Collection', $parcel);
        self::assertStringNotContainsString('function make(', $parcel);
        self::assertDirectoryDoesNotExist($root.'/Application/Support');
        self::assertDirectoryDoesNotExist($root.'/Application/Exception');

        foreach (self::phpFiles() as [$file]) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString(
                'Rick\\Laravel\\Application\\Support\\Data\\Parcel',
                $source,
                $file,
            );
            self::assertStringNotContainsString(
                'Rick\\Laravel\\Application\\Interface\\ParcelItemBase',
                $source,
                $file,
            );
            self::assertStringNotContainsString(
                'Rick\\Laravel\\Application\\Exception\\ParcelItem',
                $source,
                $file,
            );
        }
    }

    public function test_execution_policy_contracts_and_plans_belong_to_domain(): void
    {
        $root = dirname(__DIR__, 2).'/src';

        self::assertSame([
            'CandidateSelectionBase.php',
            'ExternalInputSubmissionBase.php',
            'InvocationReductionBase.php',
            'StepPlanBase.php',
            'StepStrategyBase.php',
        ], array_values(array_diff(
            self::entries($root.'/Domain/Execution/Interface'),
            ['.', '..'],
        )));
        self::assertSame([
            'AwaitingCandidateSelectionPlan.php',
            'AwaitingExternalInputPlan.php',
            'ImmediateStepPlan.php',
            'InvocationStepPlan.php',
        ], array_values(array_diff(
            self::entries($root.'/Domain/Execution/Plan'),
            ['.', '..'],
        )));
        self::assertDirectoryDoesNotExist($root.'/Application/Execution/Support/Plan');
    }

    public function test_application_module_roots_do_not_contain_loose_php_classes(): void
    {
        $application = dirname(__DIR__, 2).'/src/Application';

        foreach (['Compilation', 'Execution'] as $module) {
            self::assertSame([], self::phpFilesDirectlyIn($application.'/'.$module));
        }
    }

    public function test_first_class_architecture_categories_never_live_below_support(): void
    {
        $application = dirname(__DIR__, 2).'/src/Application';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($application),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }
            if (! $entry->isDir() || ! str_contains($entry->getPathname(), '/Support/')) {
                continue;
            }

            self::assertNotContains(
                $entry->getBasename(),
                ['Exception', 'Strategy', 'ValueObject'],
                $entry->getPathname(),
            );
        }
    }

    public function test_strategy_configuration_is_explicit_and_complete(): void
    {
        $config = ConfigurationInput::map(
            require dirname(__DIR__, 2).'/config/rick.php',
            'rick',
        );
        $execution = ConfigurationInput::map($config['execution'] ?? null, 'rick.execution');
        $strategies = ConfigurationInput::map(
            $execution['strategies'] ?? null,
            'rick.execution.strategies',
        );

        self::assertSame([
            'resolve',
            'raw_prompt',
            'define_dod',
            'context',
            'generate',
            'unfold',
            'judge',
            'edit',
            'output_glue',
            'operation',
            'quality_gate',
            'grounded_verify',
            'parallel',
            'map',
            'join',
            'branch',
            'wait_for_input',
            'await_human',
            'application',
            'agent',
        ], array_keys($strategies));
    }

    public function test_dispatch_is_generic_and_queue_jobs_only_use_application_requests(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        $dispatch = (string) file_get_contents(
            $root.'/Application/Orchestration/Pipe/DispatchPipe.php',
        );
        self::assertStringNotContainsString('Compilation', $dispatch);
        self::assertStringNotContainsString('Execution', $dispatch);

        foreach ([
            $root.'/Infrastructure/Queue/Job/ContinueRunJob.php',
            $root.'/Infrastructure/Queue/Job/ExecuteInvocationJob.php',
        ] as $job) {
            $source = (string) file_get_contents($job);
            self::assertStringNotContainsString('RunRepositoryBase', $source);
            self::assertStringNotContainsString('ExecutionRepositoryBase', $source);
            self::assertStringNotContainsString('StepStrategy', $source);
            self::assertStringNotContainsString('Application\\Execution\\Pipe', $source);
            self::assertStringNotContainsString('GatewayBase', $source);
        }

        self::assertFileDoesNotExist($root.'/Application/Execution/RunLoop.php');
    }

    public function test_rick_exposes_only_the_canonical_public_api(): void
    {
        $methods = array_values(array_filter(
            (new ReflectionClass(Rick::class))->getMethods(),
            static fn (ReflectionMethod $method): bool => $method->isPublic()
                && ! $method->isConstructor()
                && $method->getDeclaringClass()->getName() === Rick::class,
        ));
        $names = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods);
        sort($names);

        self::assertSame([
            'compile',
            'delivery',
            'fake',
            'metrics',
            'pendingInput',
            'pendingInteraction',
            'pendingReview',
            'recover',
            'relayOutbox',
            'resume',
            'run',
            'runs',
            'schedule',
            'selectCandidate',
            'snapshot',
            'submitInput',
            'timeline',
            'workflow',
        ], $names);
    }

    /** @return list<string> */
    private static function directories(string $path): array
    {
        return array_values(array_filter(
            self::entries($path),
            static fn (string $entry): bool => $entry !== '.'
                && $entry !== '..'
                && is_dir($path.'/'.$entry),
        ));
    }

    /** @return list<string> */
    private static function phpFilesDirectlyIn(string $path): array
    {
        return array_values(array_filter(
            self::entries($path),
            static fn (string $entry): bool => str_ends_with($entry, '.php')
                && is_file($path.'/'.$entry),
        ));
    }

    /** @return list<string> */
    private static function entries(string $path): array
    {
        $entries = scandir($path);
        if ($entries === false) {
            self::fail("Unable to scan architecture path [{$path}].");
        }

        return $entries;
    }
}
