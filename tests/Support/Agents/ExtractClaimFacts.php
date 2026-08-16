<?php

declare(strict_types=1);

namespace Rick\Laravel\Tests\Support\Agents;

final class ExtractClaimFacts extends FixtureAgent
{
    public function instructions(): string
    {
        return 'Extract the material facts from the supplied claim document.';
    }
}
