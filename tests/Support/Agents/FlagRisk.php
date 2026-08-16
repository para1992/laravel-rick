<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Support\Agents;

final class FlagRisk extends FixtureAgent
{
    public function instructions(): string
    {
        return 'Assess the legal risk of the supplied claim facts and return a short verdict.';
    }
}
