<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\CompletionRequestFactory;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Application\Interface\IdGeneratorBase;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationCompletionPolicy;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\Candidate;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\GenerateStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class GenerateStrategy implements InvocationReductionBase, StepStrategyBase
{
    public function __construct(
        private IdGeneratorBase $ids,
        private CompletionRequestFactory $requests,
    ) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === StepType::generate()->toString();
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof GenerateStep) {
            throw new LogicException('Generate strategy received an incompatible step.');
        }

        $artifacts = [];
        foreach ($step->readArtifacts as $key) {
            $artifacts[$key] = $run->artifact($key)->content;
        }
        $context = json_encode($artifacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $prompt = "Task:\n{$run->task}\n\nDefinition of done:\n{$run->dod->toPromptString()}"
            ."\n\nInput artifacts:\n{$context}\n\nProduce one {$step->artifact->toString()} candidate.";
        $requests = [];

        for ($index = 0; $index < $step->candidateCount; $index++) {
            $requests[] = $this->requests->create(
                'rick.step.generate',
                $prompt."\n\nCandidate number: ".($index + 1),
                ResponseContract::Candidate,
                'generate_candidate',
                $step->modelPolicyId,
                ['candidate_index' => $index],
            );
        }

        if ($requests === []) {
            throw new LogicException('Generate must plan at least one candidate invocation.');
        }

        return new InvocationStepPlan(
            $requests,
            $step->minimumSuccessful === null
                ? InvocationCompletionPolicy::allRequired()
                : InvocationCompletionPolicy::minimumSuccessful($step->minimumSuccessful),
        );
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        if (! $step instanceof GenerateStep) {
            throw new LogicException('Generate strategy received an incompatible step.');
        }

        $candidates = [];
        foreach (InvocationResponses::successfulOutcomes($outcomes) as $outcome) {
            $response = $outcome->response
                ?? throw new LogicException('Succeeded candidate invocation has no response.');
            $structured = $response->structured ?? [];
            $id = $this->ids->generate();
            $content = self::string($structured['content'] ?? $response->text, 'content');
            $candidateNumber = $outcome->originalIndex + 1;
            $candidates[] = new Candidate(
                CandidateId::fromString($id),
                $step->id(),
                $step->artifact,
                'Candidate '.$candidateNumber,
                '',
                [],
                $content,
                $outcome->invocationId->toString(),
                'independent candidate generation',
                [
                    'output_key' => $step->outputKey(),
                    'invocation_id' => $outcome->invocationId->toString(),
                    'original_index' => $outcome->originalIndex,
                    'candidate_number' => $candidateNumber,
                    'attempts' => $outcome->attempts,
                ],
            );
        }

        return StepOutcome::generated($candidates);
    }

    private static function string(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new LogicException("Generated candidate field [{$field}] must be a string.");
        }

        return $value;
    }
}
