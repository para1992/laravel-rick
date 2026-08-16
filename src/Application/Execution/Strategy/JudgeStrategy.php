<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Strategy;

use LogicException;
use Rick\Laravel\Application\Execution\Support\Llm\Prompt\CompletionRequestFactory;
use Rick\Laravel\Application\Execution\Support\Reduction\InvocationResponses;
use Rick\Laravel\Domain\Execution\Interface\CandidateSelectionBase;
use Rick\Laravel\Domain\Execution\Interface\InvocationReductionBase;
use Rick\Laravel\Domain\Execution\Interface\StepPlanBase;
use Rick\Laravel\Domain\Execution\Interface\StepStrategyBase;
use Rick\Laravel\Domain\Execution\Plan\AwaitingCandidateSelectionPlan;
use Rick\Laravel\Domain\Execution\Plan\InvocationStepPlan;
use Rick\Laravel\Domain\Llm\ValueObject\ResponseContract;
use Rick\Laravel\Domain\Run\Candidate;
use Rick\Laravel\Domain\Run\CandidateDecision;
use Rick\Laravel\Domain\Run\StepOutcome;
use Rick\Laravel\Domain\Run\ValueObject\CandidateId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Interface\StepBase;
use Rick\Laravel\Domain\Workflow\Step\JudgeStep;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;

final readonly class JudgeStrategy implements CandidateSelectionBase, InvocationReductionBase, StepStrategyBase
{
    public function __construct(private CompletionRequestFactory $requests) {}

    public function supports(StepType $type): bool
    {
        return $type->toString() === StepType::judge()->toString();
    }

    public function plan(StepBase $step, WorkflowRunSnapshot $run): StepPlanBase
    {
        if (! $step instanceof JudgeStep || $run->currentCandidates === []) {
            throw new LogicException('Judge requires current candidates.');
        }

        if (! $step->automatic) {
            return new AwaitingCandidateSelectionPlan(['scope' => 'workflow_candidate']);
        }

        $candidates = array_map(
            static fn (Candidate $candidate): array => [
                'candidate_id' => $candidate->id->toString(),
                'title' => $candidate->title,
                'content' => $candidate->content,
            ],
            $run->currentCandidates,
        );
        $candidateIds = array_column($candidates, 'candidate_id');
        $payload = json_encode([
            'task' => $run->task,
            'definition_of_done' => $run->dod->toPromptString(),
            'candidates' => $candidates,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $prompt = 'Select exactly one candidate. Return its candidate_id exactly as supplied,'
            .' a score from 0 to 100, and a concise reason grounded in the definition of done.'
            ."\n\n{$payload}";

        return new InvocationStepPlan([$this->requests->create(
            'rick.step.judge',
            $prompt,
            ResponseContract::Judge,
            'judge_candidate',
            $step->modelPolicyId,
            responseSchema: self::responseSchema($candidateIds),
        )]);
    }

    public function reduce(StepBase $step, WorkflowRunSnapshot $run, array $outcomes): StepOutcome
    {
        if (! $step instanceof JudgeStep || ! $step->automatic) {
            throw new LogicException('Manual judge is reduced through candidate selection, not LLM responses.');
        }

        $successful = InvocationResponses::successfulOutcomes($outcomes);
        if (count($successful) !== 1) {
            throw new LogicException('Automatic judge requires exactly one successful response.');
        }
        $outcome = $successful[0];
        $response = $outcome->response
            ?? throw new LogicException('Succeeded judge invocation has no response.');
        $structured = $response->structured
            ?? throw new LogicException('Automatic judge requires a structured response.');
        $candidateId = CandidateId::fromString(self::string(
            $structured['selected_candidate_id'] ?? null,
            'selected_candidate_id',
        ));
        $score = $structured['score'] ?? null;
        if (! is_int($score) && ! is_float($score)) {
            throw new LogicException('Automatic judge response field [score] must be a number.');
        }
        $reason = self::string($structured['reason'] ?? null, 'reason');

        $this->assertAvailable($run, $candidateId);

        return StepOutcome::judged(
            new CandidateDecision($step->id(), $candidateId, (float) $score, $reason),
            ['judge_invocation_id' => $outcome->invocationId->toString()],
        );
    }

    public function select(
        StepBase $step,
        WorkflowRunSnapshot $run,
        CandidateId $candidateId,
    ): StepOutcome {
        if (! $step instanceof JudgeStep || $step->automatic) {
            throw new LogicException('Judge strategy received an incompatible step.');
        }

        $this->assertAvailable($run, $candidateId);

        return StepOutcome::judged(new CandidateDecision(
            $step->id(),
            $candidateId,
            null,
            'Selected through external review.',
            'manual',
        ));
    }

    private function assertAvailable(WorkflowRunSnapshot $run, CandidateId $candidateId): void
    {
        foreach ($run->currentCandidates as $candidate) {
            if ($candidate->id->toString() === $candidateId->toString()) {
                return;
            }
        }

        throw new LogicException(sprintf(
            'Candidate [%s] is not available for selection.',
            $candidateId->toString(),
        ));
    }

    private static function string(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new LogicException("Automatic judge response field [{$field}] must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param  non-empty-list<string>  $candidateIds
     * @return array<string, mixed>
     */
    private static function responseSchema(array $candidateIds): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'selected_candidate_id' => [
                    'type' => 'string',
                    'enum' => $candidateIds,
                ],
                'score' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 100,
                ],
                'reason' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'pattern' => '\\S',
                ],
            ],
            'required' => ['selected_candidate_id', 'score', 'reason'],
            'additionalProperties' => false,
        ];
    }
}
