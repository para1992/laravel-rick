<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Unit\Application\Orchestration\EntryPoint;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;
use Rick\Laravel\Application\Interface\PipeBase;
use Rick\Laravel\Application\Interface\RequestBase;
use Rick\Laravel\Application\Interface\ResultBase;
use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Domain\Interface\ParcelItemBase;
use Rick\Laravel\Domain\ValueObject\Parcel;

final class HandlerTest extends TestCase
{
    public function test_it_moves_a_parcel_through_injected_pipes_in_order(): void
    {
        $result = new TestResult;
        $entryPoint = new Handler(
            new Pipeline(new Container),
            [
                new PreparePipe,
                new FinishPipe($result),
            ],
        );

        $handled = $entryPoint->handle(
            Parcel::fromArray([new TestRequest]),
        );

        self::assertSame($result, $handled->get(TestResult::class));
        self::assertFalse($handled->has(TestRequest::class));
        self::assertFalse($handled->has(PreparedItem::class));
    }
}

final class TestRequest implements RequestBase {}

final class TestResult implements ResultBase {}

final class PreparedItem implements ParcelItemBase {}

final class PreparePipe implements PipeBase
{
    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        $parcel->get(TestRequest::class);

        return $next($parcel->put(new PreparedItem));
    }
}

final readonly class FinishPipe implements PipeBase
{
    public function __construct(private TestResult $result) {}

    /** @param Closure(Parcel): Parcel $next */
    public function process(Parcel $parcel, Closure $next): Parcel
    {
        $parcel->get(PreparedItem::class);

        return $next(
            $parcel
                ->put($this->result)
                ->delete(RequestBase::class)
                ->delete(PreparedItem::class),
        );
    }
}
