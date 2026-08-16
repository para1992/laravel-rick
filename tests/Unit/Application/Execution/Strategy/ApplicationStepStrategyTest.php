<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Execution\Strategy;

use Illuminate\Container\Container;
use LogicException;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Execution\Exception\ApplicationStepException;
use Rick\Laravel\Application\Execution\Interface\StepFailureBase;
use Rick\Laravel\Application\Execution\Strategy\ApplicationStepStrategy;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\Plan\ImmediateStepPlan;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationId;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use Rick\Laravel\Domain\Run\RunInput;
use Rick\Laravel\Domain\Run\RunStatus;
use Rick\Laravel\Domain\Run\ValueObject\RunId;
use Rick\Laravel\Domain\Run\WorkflowRunSnapshot;
use Rick\Laravel\Domain\Workflow\Step\ApplicationStep;
use Rick\Laravel\Domain\Workflow\ValueObject\DefinitionOfDone;
use Rick\Laravel\Domain\Workflow\ValueObject\StepId;
use Rick\Laravel\Domain\Workflow\ValueObject\StepType;
use Rick\Laravel\WorkflowState;
use RuntimeException;
use stdClass;

final class ApplicationStepStrategyTest extends TestCase
{
    public function test_plan_invokes_the_handler_and_returns_an_immediate_plan_with_the_artifact(): void
    {
        $strategy = new ApplicationStepStrategy(new Container);

        $plan = $strategy->plan($this->step(ContractHandler::class, version: 3), $this->snapshot());

        self::assertInstanceOf(ImmediateStepPlan::class, $plan);
        self::assertSame([
            'handler_class' => ContractHandler::class,
            'handler_version' => 3,
        ], $plan->outcome->stepState);
        self::assertCount(1, $plan->outcome->artifacts);

        $artifact = $plan->outcome->artifacts[0];
        self::assertSame('contract', $artifact->key);
        self::assertSame('application', $artifact->type->toString());
        self::assertSame(
            ['id' => 'c-1', 'terms' => 'active'],
            $artifact->payload,
        );
        self::assertSame(
            json_encode(
                ['id' => 'c-1', 'terms' => 'active'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
            $artifact->content,
        );
    }

    public function test_plan_wraps_a_throwing_handler_in_application_step_exception(): void
    {
        $strategy = new ApplicationStepStrategy(new Container);

        try {
            $strategy->plan($this->step(ThrowingHandler::class), $this->snapshot());
            self::fail('Expected the handler failure to surface as an ApplicationStepException.');
        } catch (ApplicationStepException $error) {
            self::assertInstanceOf(StepFailureBase::class, $error);
            self::assertSame('application_step_failed', $error->errorCode());
            self::assertSame('Handler exploded.', $error->getMessage());
            self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
            self::assertSame('Handler exploded.', $error->getPrevious()->getMessage());
        }
    }

    public function test_plan_fails_when_the_resolved_handler_is_not_callable(): void
    {
        $strategy = new ApplicationStepStrategy(new Container);

        $this->expectException(ApplicationStepException::class);
        $this->expectExceptionMessage('Application step handler [stdClass] is not callable.');

        $strategy->plan($this->step(stdClass::class), $this->snapshot());
    }

    public function test_reduce_throws_because_application_steps_are_immediate(): void
    {
        $strategy = new ApplicationStepStrategy(new Container);

        $this->expectException(LogicException::class);

        $strategy->reduce(
            $this->step(ContractHandler::class),
            $this->snapshot(),
            [new InvocationOutcome(
                InvocationId::fromString('invocation-1'),
                0,
                1,
                InvocationStatus::Succeeded,
                new CompletionResponse('Text'),
                null,
                null,
            )],
        );
    }

    public function test_supports_accepts_application_and_rejects_other_types(): void
    {
        $strategy = new ApplicationStepStrategy(new Container);

        self::assertTrue($strategy->supports(StepType::application()));
        self::assertTrue($strategy->supports(StepType::fromString('application')));
        self::assertFalse($strategy->supports(StepType::context()));
        self::assertFalse($strategy->supports(StepType::generate()));
        self::assertFalse($strategy->supports(StepType::resolve()));
    }

    private function step(string $handlerClass, int $version = 1): ApplicationStep
    {
        return new ApplicationStep(
            StepId::fromString('app-step'),
            $handlerClass,
            handlerVersion: $version,
        );
    }

    private function snapshot(): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            RunId::fromString('run-app'),
            RunStatus::Running,
            1,
            new RunInput([]),
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
            10,
        );
    }
}

final class ContractHandler
{
    public function __invoke(WorkflowState $state): void
    {
        $state->put('contract', ['id' => 'c-1', 'terms' => 'active']);
    }
}

final class ThrowingHandler
{
    public function __invoke(WorkflowState $state): void
    {
        throw new RuntimeException('Handler exploded.');
    }
}
