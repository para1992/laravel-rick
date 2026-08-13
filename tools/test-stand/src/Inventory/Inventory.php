<?php

declare(strict_types=1);

namespace Rick\Stand\Inventory;

use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use Rick\Laravel\Application\Execution\Result\ContinueRunStatus;
use Rick\Laravel\Domain\Execution\InvocationAttemptStatus;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\StepExecutionStatus;
use Rick\Laravel\Domain\Execution\UnfoldPhase;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionMode;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderIdSource;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredDecodeStatus;
use Rick\Laravel\Domain\Execution\ValueObject\StructuredResponseStage;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\RunRecoveryAction;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Rick;
use Rick\Stand\Package\PackageLocator;
use Rick\Stand\Support\StrictJson;
use RuntimeException;

final class Inventory
{
    /** @return list<Element> */
    public function discover(): array
    {
        $root = PackageLocator::root();
        $config = require $root.'/config/rick.php';
        if (! is_array($config)) {
            throw new RuntimeException('Package configuration did not return an array.');
        }

        $elements = [];
        $this->publicApi($elements);
        $this->strategies($elements, $config);
        $this->useCases($elements, $root);
        $this->capabilities($elements, $root);
        $this->enum($elements, ResponseContract::class, 'response_contract');
        $this->enum($elements, ProviderRequestOutcome::class, 'provider_outcome');
        foreach ([
            RunStatus::class,
            InvocationStatus::class,
            InvocationAttemptStatus::class,
            StepExecutionStatus::class,
            UnfoldPhase::class,
            RunRecoveryAction::class,
            StructuredDecodeStatus::class,
            StructuredResponseStage::class,
            InvocationCompletionMode::class,
            ProviderIdSource::class,
            ContinueRunStatus::class,
        ] as $enum) {
            $this->enum($elements, $enum, 'lifecycle');
        }
        $this->codecs($elements, $root);
        $this->configuredLlm($elements, $config);
        $this->quality($elements, $config, $root);
        $this->recipes($elements, $root);
        $this->commands($elements, $root);
        $this->platform($elements);

        usort($elements, static fn (Element $left, Element $right): int => $left->id <=> $right->id);
        $ids = array_map(static fn (Element $element): string => $element->id, $elements);
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Inventory contains duplicate element IDs.');
        }

        return $elements;
    }

    /** @return array<string, Element> */
    public function indexed(): array
    {
        $indexed = [];
        foreach ($this->discover() as $element) {
            $indexed[$element->id] = $element;
        }

        return $indexed;
    }

    /** @param list<Element> $elements */
    private function publicApi(array &$elements): void
    {
        $methods = array_filter(
            (new ReflectionClass(Rick::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === Rick::class
                && $method->getName() !== '__construct',
        );
        foreach ($methods as $method) {
            $elements[] = new Element('public.'.$method->getName(), 'public_api');
        }
    }

    /** @param list<Element> $elements @param array<string, mixed> $config */
    private function strategies(array &$elements, array $config): void
    {
        $strategies = $config['execution']['strategies'] ?? null;
        if (! is_array($strategies) || array_is_list($strategies)) {
            throw new RuntimeException('rick.execution.strategies must be an explicit map.');
        }
        foreach ($strategies as $type => $class) {
            if (! is_string($type) || ! is_string($class) || ! class_exists($class)) {
                throw new RuntimeException('Configured strategy map contains an invalid entry.');
            }
            $elements[] = new Element('strategy.'.$type, 'strategy', ['class' => $class]);
        }
    }

    /** @param list<Element> $elements */
    private function useCases(array &$elements, string $root): void
    {
        $requestFiles = glob($root.'/src/Application/Execution/Request/*Request.php') ?: [];
        foreach ($requestFiles as $requestFile) {
            $name = basename($requestFile, 'Request.php');
            foreach (['Pipe', 'Result'] as $suffix) {
                $path = $root.'/src/Application/Execution/'.$suffix.'/'.$name.$suffix.'.php';
                if (! is_file($path)) {
                    throw new RuntimeException("Execution use case [{$name}] has no {$suffix}.");
                }
            }
            $elements[] = new Element('use_case.'.$name, 'use_case');
        }
    }

    /** @param list<Element> $elements */
    private function capabilities(array &$elements, string $root): void
    {
        $path = $root.'/capabilities.json';
        if (! is_file($path)) {
            $path = dirname(__DIR__, 2).'/inventory/capabilities.json';
        }
        $manifest = StrictJson::file($path);
        $capabilities = StrictJson::object($manifest['capabilities'] ?? null, 'capabilities');
        foreach ($capabilities as $id => $status) {
            if ($status === 'implemented') {
                $elements[] = new Element('capability.'.$id, 'capability');
            }
        }
    }

    /** @param list<Element> $elements @param class-string $enum */
    private function enum(array &$elements, string $enum, string $category): void
    {
        $reflection = new ReflectionEnum($enum);
        foreach ($reflection->getCases() as $case) {
            $value = $case->getBackingValue();
            $elements[] = new Element(
                $category.'.'.$reflection->getShortName().'.'.(is_string($value) ? $value : $case->getName()),
                $category,
                ['enum' => $enum],
            );
        }
    }

    /** @param list<Element> $elements */
    private function codecs(array &$elements, string $root): void
    {
        foreach (glob($root.'/src/Infrastructure/Persistence/Json/*Codec.php') ?: [] as $path) {
            $elements[] = new Element('codec.'.basename($path, '.php'), 'codec');
        }
    }

    /** @param list<Element> $elements @param array<string, mixed> $config */
    private function configuredLlm(array &$elements, array $config): void
    {
        foreach (['operations' => 'operation', 'step_prompts' => 'prompt'] as $key => $category) {
            $entries = $config['llm'][$key] ?? null;
            if (! is_array($entries) || array_is_list($entries)) {
                throw new RuntimeException("rick.llm.{$key} must be a map.");
            }
            foreach (array_keys($entries) as $id) {
                $elements[] = new Element($category.'.'.$id, $category);
            }
        }
    }

    /** @param list<Element> $elements @param array<string, mixed> $config */
    private function quality(array &$elements, array $config, string $root): void
    {
        $sets = $config['quality']['rule_sets'] ?? null;
        if (! is_array($sets) || array_is_list($sets)) {
            throw new RuntimeException('rick.quality.rule_sets must be a map.');
        }
        foreach ($sets as $id => $rules) {
            $elements[] = new Element('quality_rule_set.'.$id, 'quality');
            if (! is_array($rules)) {
                throw new RuntimeException("Quality rule set [{$id}] must be a list.");
            }
            foreach ($rules as $rule) {
                if (is_array($rule) && is_string($rule['type'] ?? null)) {
                    $elements[] = new Element('quality_rule.'.$rule['type'], 'quality');
                }
            }
        }
        foreach (glob($root.'/src/Application/Execution/Support/Quality/Policy/*Policy.php') ?: [] as $path) {
            $elements[] = new Element('quality_policy.'.basename($path, '.php'), 'quality');
        }
    }

    /** @param list<Element> $elements */
    private function recipes(array &$elements, string $root): void
    {
        foreach (glob($root.'/src/Application/Compilation/Support/Recipe/*Recipe.php') ?: [] as $path) {
            $class = 'Rick\\Laravel\\Application\\Compilation\\Support\\Recipe\\'.basename($path, '.php');
            if (! class_exists($class) || ! method_exists($class, 'id')) {
                continue;
            }
            $id = (new $class)->id();
            $elements[] = new Element('recipe.'.$id, 'recipe', ['class' => $class]);
        }
    }

    /** @param list<Element> $elements */
    private function commands(array &$elements, string $root): void
    {
        foreach (glob($root.'/src/Infrastructure/Console/*Command.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            if (preg_match('/protected \$signature = \'([A-Za-z0-9:_-]+)/', $source, $matches) === 1) {
                $elements[] = new Element('command.'.$matches[1], 'command');
            }
        }
    }

    /** @param list<Element> $elements */
    private function platform(array &$elements): void
    {
        $platform = StrictJson::file(dirname(__DIR__, 2).'/inventory/platform.json');
        foreach ($platform as $category => $values) {
            foreach (StrictJson::list($values, "platform.{$category}") as $value) {
                $id = StrictJson::string($value, "platform.{$category}");
                $elements[] = new Element("platform.{$category}.{$id}", 'platform');
            }
        }
    }
}
