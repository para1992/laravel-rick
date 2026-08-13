<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application;

use LogicException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Compilation\Exception\WorkflowValidationException;
use Rick\Laravel\Application\Compilation\Support\Builder\WorkflowBuilder;
use Rick\Laravel\Application\Execution\Request\FailInvocationRequest;
use Rick\Laravel\Application\Execution\Request\ResumeRunRequest;
use Rick\Laravel\Application\Execution\Result\FailInvocationResult;
use Rick\Laravel\Application\Execution\Result\ResumeRunResult;
use Rick\Laravel\Application\Execution\Strategy\ContextStrategy;
use Rick\Laravel\Application\Execution\Strategy\JoinStrategy;
use Rick\Laravel\Application\Execution\Strategy\OutputGlueStrategy;
use Rick\Laravel\Application\Execution\Strategy\ResolveStrategy;
use Rick\Laravel\Application\Execution\Strategy\WaitForInputStrategy;
use Rick\Laravel\Application\Gate\Exception\GateInputViolationException;
use Rick\Laravel\Application\Gate\Exception\GateOutputViolationException;
use Rick\Laravel\Application\Interface\GateContractBase;
use Rick\Laravel\Application\Interface\JsonSchemaValidatorBase;
use Rick\Laravel\Application\Orchestration\Exception\ModuleNotFoundException;
use Rick\Laravel\Domain\Exception\ExceptionBase;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Step\ContextStep;
use Rick\Laravel\Domain\Workflow\Step\GenerateStep;
use Rick\Laravel\Domain\Workflow\ValueObject\ArtifactType;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use RuntimeException;

final class ContractSurfaceTest extends TestCase
{
    public function test_exception_contracts_expose_safe_codes_retryability_and_context(): void
    {
        $workflow = new WorkflowValidationException('Invalid workflow.');
        self::assertSame('workflow_validation_failed', $workflow->errorCode());
        self::assertFalse($workflow->retryable());

        $domain = new class('Failure', 'safe_code', true) extends ExceptionBase {};
        self::assertSame('safe_code', $domain->errorCode());
        self::assertTrue($domain->retryable());

        $contract = self::createStub(GateContractBase::class);
        $previous = new RuntimeException('Missing parcel item.');
        $input = GateInputViolationException::for($contract, 'InputType', $previous);
        $output = GateOutputViolationException::for($contract, 'OutputType', $previous);
        self::assertStringContainsString('InputType', $input->getMessage());
        self::assertSame($previous, $input->getPrevious());
        self::assertStringContainsString('OutputType', $output->getMessage());
        self::assertSame($previous, $output->getPrevious());
        self::assertStringContainsString('No Application module', ModuleNotFoundException::forParcel()->getMessage());
    }

    public function test_internal_request_and_result_parcels_preserve_exact_values(): void
    {
        $invocationId = InvocationId::fromString('invocation-1');
        $runId = RunId::fromString('run-1');
        $failRequest = new FailInvocationRequest($invocationId, 'failed', 'Failure');
        $resumeRequest = new ResumeRunRequest($runId);
        $failResult = new FailInvocationResult($invocationId);
        $resumeResult = new ResumeRunResult($this->snapshot());

        self::assertSame($invocationId, $failRequest->invocationId);
        self::assertSame('failed', $failRequest->errorCode);
        self::assertSame($runId, $resumeRequest->runId);
        self::assertSame($invocationId, $failResult->invocationId);
        self::assertSame('run-1', $resumeResult->run->id->toString());
    }

    public function test_builder_custom_step_and_generate_write_contract_are_explicit(): void
    {
        $context = new ContextStep(StepId::fromString('custom-context'), 'brief');
        $definition = WorkflowBuilder::named('custom')->step($context)->build();
        self::assertSame([$context], $definition->steps);

        $generate = new GenerateStep(
            StepId::fromString('generate'),
            ArtifactType::fromString('draft'),
            1,
        );
        self::assertSame([], $generate->artifactWrites());
    }

    public function test_immediate_strategies_reject_invocation_reduction(): void
    {
        $step = new ContextStep(StepId::fromString('context'), 'brief');
        $strategies = [
            new ResolveStrategy,
            new ContextStrategy,
            new JoinStrategy,
            new OutputGlueStrategy,
            new WaitForInputStrategy(self::createStub(JsonSchemaValidatorBase::class)),
        ];
        $outcomes = [new InvocationOutcome(
            InvocationId::fromString('incompatible'),
            0,
            1,
            InvocationStatus::Failed,
            null,
            'failed',
            'Failed',
        )];

        foreach ($strategies as $strategy) {
            try {
                $strategy->reduce($step, $this->snapshot(), $outcomes);
                self::fail('An immediate strategy accepted invocation reduction.');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function snapshot(): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            RunId::fromString('run-1'),
            RunStatus::Running,
            1,
            new RunInput(['brief' => 'Brief']),
            'Task',
            DefinitionOfDone::automatic(),
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            0,
            1,
        );
    }
}
