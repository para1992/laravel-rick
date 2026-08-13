<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Feature;

use Rick\Laravel\Application\Orchestration\EntryPoint\Handler;
use Rick\Laravel\Rick;
use Rick\Laravel\Tests\TestCase;

final class PestPackageTest extends TestCase
{
    public function test_it_boots_the_service_provider_in_a_laravel_application(): void
    {
        self::assertInstanceOf(Rick::class, $this->application()->make(Rick::class));
        self::assertInstanceOf(Handler::class, $this->application()->make(Handler::class));
    }

    public function test_it_registers_the_package_operational_commands(): void
    {
        $this->artisanCommand('rick:recipes')->assertSuccessful();
        $this->artisanCommand('rick:recover')->assertSuccessful();
    }
}
