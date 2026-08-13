<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Reduction;

use LogicException;
use Rick\Laravel\Domain\Execution\InvocationStatus;
use Rick\Laravel\Domain\Execution\ValueObject\InvocationOutcome;
use Rick\Laravel\Domain\Llm\ValueObject\CompletionResponse;

final readonly class InvocationResponses
{
    /**
     * @param  non-empty-list<InvocationOutcome>  $outcomes
     * @return non-empty-list<CompletionResponse>
     */
    public static function successful(array $outcomes): array
    {
        $responses = array_map(
            static fn (InvocationOutcome $outcome): CompletionResponse => $outcome->response
                ?? throw new LogicException('Succeeded invocation has no response.'),
            self::successfulOutcomes($outcomes),
        );

        return $responses;
    }

    /**
     * @param  non-empty-list<InvocationOutcome>  $outcomes
     * @return non-empty-list<InvocationOutcome>
     */
    public static function successfulOutcomes(array $outcomes): array
    {
        $successful = [];
        foreach ($outcomes as $outcome) {
            if ($outcome->status === InvocationStatus::Succeeded) {
                $successful[] = $outcome;
            }
        }
        if ($successful === []) {
            throw new LogicException('Invocation reduction requires a successful response.');
        }

        return $successful;
    }
}
