<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Pipe;

use Closure;
use Rick\Laravel\Application\Execution\Request\FailInvocationRequest;
use Rick\Laravel\Application\Execution\Result\FailInvocationResult;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final readonly class FailInvocationPipe implements PipeBase
{
    public function __construct(private ExecuteInvocationPipe $invocations) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        if (! $parcel->has(FailInvocationRequest::class)) {
            return $next($parcel);
        }

        $request = $parcel->get(FailInvocationRequest::class);
        $this->invocations->fail(
            $request->invocationId,
            $request->errorCode,
            $request->message,
            true,
        );

        return $next($parcel->put(new FailInvocationResult($request->invocationId)));
    }
}
