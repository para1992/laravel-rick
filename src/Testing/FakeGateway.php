<?php

declare(strict_types=1);

namespace Rick\Laravel\Testing;

use Closure;
use InvalidArgumentException;
use Rick\Laravel\Application\Execution\Exception\ProviderRequestException;
use Rick\Laravel\Application\Execution\Support\Llm\Interface\GatewayBase;
use Rick\Laravel\Domain\Execution\ValueObject\ProviderRequestOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionMetrics;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionRequest;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;
use RuntimeException;
use Throwable;

final class FakeGateway implements GatewayBase
{
    /** @var list<CompletionResponse|Throwable|Closure(CompletionRequest): CompletionResponse> */
    private array $responses = [];

    /** @var list<CompletionRequest> */
    private array $requests = [];

    /** @var (Closure(CompletionRequest): CompletionResponse)|null */
    private ?Closure $responder = null;

    public function respond(CompletionResponse $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function throw(Throwable $error): self
    {
        $this->responses[] = $error;

        return $this;
    }

    /** @param callable(CompletionRequest): CompletionResponse $responder */
    public function respondUsing(callable $responder): self
    {
        $this->responder = $responder instanceof Closure
            ? $responder
            : Closure::fromCallable($responder);

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $structured
     * @param  array<string, mixed>  $metadata
     */
    public function respondMeasured(
        string $text,
        ?array $structured,
        CompletionMetrics $metrics,
        string $provider = 'fake',
        string $model = 'fake',
        array $metadata = [],
    ): self {
        return $this->respond(new CompletionResponse(
            $text,
            $structured,
            $provider,
            $model,
            $metadata,
            $metrics,
        ));
    }

    public function reject(
        string $safeCode = 'provider_request_rejected',
        string $safeMessage = 'The fake provider did not accept the request.',
        bool $retryable = false,
        ProviderRequestOutcome $outcome = ProviderRequestOutcome::NotAccepted,
        ?string $requestId = null,
        ?string $httpStatusClass = null,
    ): self {
        return $this->throw(new ProviderRequestException(
            safeCode: $safeCode,
            safeMessage: $safeMessage,
            retryable: $retryable,
            outcome: $outcome,
            requestId: $requestId,
            httpStatusClass: $httpStatusClass,
        ));
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses);
        if ($next instanceof Throwable) {
            throw $next;
        }

        if ($next instanceof Closure) {
            return $next($request);
        }

        if ($next === null && $this->responder !== null) {
            return ($this->responder)($request);
        }

        return $next ?? throw new RuntimeException('Fake gateway has no queued response.');
    }

    /** @return list<CompletionRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * @param  (callable(CompletionRequest): bool)|null  $predicate
     */
    public function assertRequested(?callable $predicate = null, int $times = 1): self
    {
        if ($times < 1) {
            throw new InvalidArgumentException('Expected request count must be positive.');
        }
        $matches = $predicate === null
            ? count($this->requests)
            : count(array_filter($this->requests, $predicate));
        if ($matches !== $times) {
            throw new RuntimeException(
                "Expected {$times} matching fake gateway request(s); observed {$matches}.",
            );
        }

        return $this;
    }

    public function assertNothingRequested(): self
    {
        if ($this->requests !== []) {
            throw new RuntimeException(sprintf(
                'Expected no fake gateway requests; observed %d.',
                count($this->requests),
            ));
        }

        return $this;
    }
}
