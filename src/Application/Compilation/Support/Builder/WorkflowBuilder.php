<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Compilation\Support\Builder;

use Closure;
use Rick\Laravel\Domain\Metrics\ValueObject\InvocationCost;
use Rick\Laravel\Domain\Run\ValueObject\ResourceBudget;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\OperationCall;
use Rick\Laravel\Domain\Workflow\Step\AwaitHumanStep;
use Rick\Laravel\Domain\Workflow\Step\BranchStep;
use Rick\Laravel\Domain\Workflow\Step\ContextStep;
use Rick\Laravel\Domain\Workflow\Step\EditStep;
use Rick\Laravel\Domain\Workflow\Step\GenerateStep;
use Rick\Laravel\Domain\Workflow\Step\GroundedVerifyStep;
use Rick\Laravel\Domain\Workflow\Step\JoinStep;
use Rick\Laravel\Domain\Workflow\Step\JudgeStep;
use Rick\Laravel\Domain\Workflow\Step\LlmOperationStep;
use Rick\Laravel\Domain\Workflow\Step\MapStep;
use Rick\Laravel\Domain\Workflow\Step\OutputGlueStep;
use Rick\Laravel\Domain\Workflow\Step\ParallelStep;
use Rick\Laravel\Domain\Workflow\Step\QualityGateStep;
use Rick\Laravel\Domain\Workflow\Step\RawPromptStep;
use Rick\Laravel\Domain\Workflow\Step\ResolveStep;
use Rick\Laravel\Domain\Workflow\Step\UnfoldStep;
use Rick\Laravel\Domain\Workflow\Step\WaitForInputStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\WorkflowDefinition;

final class WorkflowBuilder
{
    /** @var list<StepBase> */
    private array $steps = [];

    private string $version = '1.0.0';

    private int $position = 1;

    private ?ResourceBudget $budget = null;

    private readonly string $name;

    public function __construct(string $name)
    {
        $this->name = trim($name);
    }

    public static function named(string $name): self
    {
        return new self($name);
    }

    public function version(string $version): self
    {
        $this->version = trim($version);

        return $this;
    }

    public function resourceBudget(ResourceBudget $budget): self
    {
        $this->budget = $budget;

        return $this;
    }

    public function budget(
        ?int $maxInputTokens = null,
        ?int $maxOutputTokens = null,
        ?int $maxTotalTokens = null,
        int|float|string|null $maxCostUsd = null,
        ?int $maxLatencyMilliseconds = null,
        ?int $maxDurationMilliseconds = null,
        int $defaultOutputReservationTokens = 2048,
        bool $requireCompleteMetrics = false,
        bool $requireKnownPricing = true,
    ): self {
        return $this->resourceBudget(new ResourceBudget(
            $maxInputTokens,
            $maxOutputTokens,
            $maxTotalTokens,
            $maxCostUsd === null ? null : InvocationCost::fromUsd($maxCostUsd),
            $maxLatencyMilliseconds,
            $maxDurationMilliseconds,
            $defaultOutputReservationTokens,
            $requireCompleteMetrics,
            $requireKnownPricing,
        ));
    }

    public function resolve(string $task, string|DefinitionOfDone $dod): self
    {
        $this->steps[] = new ResolveStep(
            $this->id('resolve'),
            $task,
            is_string($dod) ? DefinitionOfDone::fromString($dod) : $dod,
        );

        return $this;
    }

    public function rawPrompt(string $prompt, string $modelPolicy = 'default'): self
    {
        $this->steps[] = new RawPromptStep(
            $this->id('raw_prompt'),
            $prompt,
            $modelPolicy,
        );

        return $this;
    }

    public function angle(int $candidates = 3, ?int $minimumSuccessful = null): self
    {
        return $this->generate('angle', $candidates, minimumSuccessful: $minimumSuccessful);
    }

    public function plan(int $candidates = 3, ?int $minimumSuccessful = null): self
    {
        return $this->generate('plan', $candidates, minimumSuccessful: $minimumSuccessful);
    }

    public function draft(int $candidates = 3, ?int $minimumSuccessful = null): self
    {
        return $this->generate('draft', $candidates, minimumSuccessful: $minimumSuccessful);
    }

    /** @param list<string> $reads */
    public function generate(
        string $artifact,
        int $candidates = 1,
        ?string $outputKey = null,
        array $reads = [],
        string $modelPolicy = 'default',
        ?int $minimumSuccessful = null,
    ): self {
        $this->steps[] = new GenerateStep(
            $this->id('generate'),
            ArtifactType::fromString($artifact),
            $candidates,
            $outputKey,
            $reads,
            $modelPolicy,
            $minimumSuccessful,
        );

        return $this;
    }

    public function context(string $inputKey): self
    {
        $this->steps[] = new ContextStep($this->id('context'), $inputKey);

        return $this;
    }

    public function unfold(
        string $sourceArtifact,
        string $childArtifact,
        int $candidates = 1,
        int $maxUnits = 20,
        string $modelPolicy = 'default',
    ): self {
        $this->steps[] = new UnfoldStep(
            $this->id('unfold'),
            ArtifactType::fromString($sourceArtifact),
            ArtifactType::fromString($childArtifact),
            $candidates,
            false,
            $maxUnits,
            $modelPolicy,
        );

        return $this;
    }

    public function unfoldManualJudge(
        string $sourceArtifact,
        string $childArtifact,
        int $candidates = 3,
        int $maxUnits = 20,
        string $modelPolicy = 'default',
    ): self {
        $this->steps[] = new UnfoldStep(
            $this->id('unfold'),
            ArtifactType::fromString($sourceArtifact),
            ArtifactType::fromString($childArtifact),
            $candidates,
            true,
            $maxUnits,
            $modelPolicy,
        );

        return $this;
    }

    public function manualJudge(): self
    {
        $this->steps[] = new JudgeStep($this->id('judge'));

        return $this;
    }

    public function judge(string $modelPolicy = 'quality'): self
    {
        $this->steps[] = new JudgeStep($this->id('judge'), true, $modelPolicy);

        return $this;
    }

    public function edit(string $mode = 'strict', string $modelPolicy = 'default'): self
    {
        $this->steps[] = new EditStep($this->id('edit'), $mode, $modelPolicy);

        return $this;
    }

    /**
     * @param  list<string>  $reads
     * @param  array<string, mixed>  $parameters
     */
    public function operation(
        string $operation,
        string $output,
        array $reads = [],
        array $parameters = [],
        ?string $version = null,
    ): self {
        $this->steps[] = new LlmOperationStep(
            $this->id('operation'),
            $operation,
            $version,
            $reads,
            $output,
            $parameters,
        );

        return $this;
    }

    public function qualityGate(
        string $artifact,
        string $rules,
        string $policy = 'fail',
        ?string $repairOperation = null,
        int $maxRepairs = 0,
        ?string $output = null,
        ?string $repairOperationVersion = null,
    ): self {
        $this->steps[] = new QualityGateStep(
            $this->id('quality_gate'),
            $artifact,
            $rules,
            $policy,
            $repairOperation,
            $repairOperationVersion,
            $maxRepairs,
            $output,
        );

        return $this;
    }

    /** @param non-empty-list<string> $evidence */
    public function groundedVerify(
        string $artifact,
        array $evidence,
        ?string $repairOperation = null,
        int $maxRepairs = 0,
        ?string $output = null,
        int $minimumQuoteCharacters = 12,
        string $verificationOperation = 'rick.verify.grounded',
        ?string $verificationOperationVersion = null,
        ?string $repairOperationVersion = null,
    ): self {
        $this->steps[] = new GroundedVerifyStep(
            $this->id('grounded_verify'),
            $artifact,
            $evidence,
            $verificationOperation,
            $verificationOperationVersion,
            $repairOperation,
            $repairOperationVersion,
            $maxRepairs,
            $output,
            $minimumQuoteCharacters,
        );

        return $this;
    }

    /** @param non-empty-list<OperationCall>|Closure(ParallelBuilder): mixed $calls */
    public function parallel(array|Closure $calls): self
    {
        if ($calls instanceof Closure) {
            $builder = new ParallelBuilder;
            $calls($builder);
            $calls = $builder->calls();
        }

        $this->steps[] = new ParallelStep($this->id('parallel'), $calls);

        return $this;
    }

    /** @param array<string, mixed> $parameters */
    public function map(
        string $source,
        string $path,
        string $operation,
        string $output,
        array $parameters = [],
        int $maxItems = 50,
        ?string $operationVersion = null,
        bool $includeSourceArtifact = false,
    ): self {
        $this->steps[] = new MapStep(
            $this->id('map'),
            $source,
            $path,
            $operation,
            $operationVersion,
            $output,
            $parameters,
            $maxItems,
            $includeSourceArtifact,
        );

        return $this;
    }

    /** @param non-empty-list<string> $inputs */
    public function join(array $inputs, string $output, string $mode = 'concat', string $separator = "\n\n"): self
    {
        $this->steps[] = new JoinStep($this->id('join'), $inputs, $output, $mode, $separator);

        return $this;
    }

    public function branch(
        string $conditionArtifact,
        string $path,
        string $operator,
        mixed $expected,
        string $whenTrue,
        string $whenFalse,
        string $output,
    ): self {
        $this->steps[] = new BranchStep(
            $this->id('branch'),
            $conditionArtifact,
            $path,
            $operator,
            $expected,
            $whenTrue,
            $whenFalse,
            $output,
        );

        return $this;
    }

    /** @param array<string, mixed>|null $schema */
    public function waitForInput(
        string $key,
        string $prompt,
        ?array $schema = null,
        string $artifactType = 'input',
    ): self {
        $this->steps[] = new WaitForInputStep(
            $this->id('wait_for_input'),
            $key,
            $prompt,
            ArtifactType::fromString($artifactType),
            $schema,
        );

        return $this;
    }

    /** @param array<string, mixed>|null $schema */
    public function awaitHuman(
        string $key,
        string $prompt,
        ?array $schema = null,
        string $artifactType = 'approval',
    ): self {
        $this->steps[] = new AwaitHumanStep(
            $this->id('await_human'),
            $key,
            $prompt,
            ArtifactType::fromString($artifactType),
            $schema,
        );

        return $this;
    }

    public function outputGlue(?string $artifactKey = null): self
    {
        $this->steps[] = new OutputGlueStep($this->id('output_glue'), $artifactKey);

        return $this;
    }

    public function step(StepBase $step): self
    {
        $this->steps[] = $step;

        return $this;
    }

    public function build(): WorkflowDefinition
    {
        return new WorkflowDefinition($this->name, $this->version, $this->steps, $this->budget);
    }

    private function id(string $type): StepId
    {
        return StepId::fromString(sprintf('%03d_%s', $this->position++, $type));
    }
}
