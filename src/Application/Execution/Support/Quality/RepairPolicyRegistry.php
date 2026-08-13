<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Quality;

use OutOfBoundsException;
use Rick\Laravel\Application\Execution\Support\Quality\Interface\RepairPolicyBase;
use Rick\Laravel\Application\Execution\Support\Quality\Policy\BoundedRepairPolicy;
use Rick\Laravel\Application\Execution\Support\Quality\Policy\FailRepairPolicy;

final class RepairPolicyRegistry
{
    /** @var array<string, RepairPolicyBase> */
    private array $policies = [];

    /** @param iterable<RepairPolicyBase> $policies */
    public function __construct(iterable $policies = [])
    {
        $this->register(new FailRepairPolicy);
        $this->register(new BoundedRepairPolicy);
        foreach ($policies as $policy) {
            $this->register($policy);
        }
    }

    public function register(RepairPolicyBase $policy): void
    {
        $this->policies[$policy->id()] = $policy;
    }

    public function get(string $id): RepairPolicyBase
    {
        return $this->policies[$id]
            ?? throw new OutOfBoundsException("Repair policy [{$id}] is not registered.");
    }
}
