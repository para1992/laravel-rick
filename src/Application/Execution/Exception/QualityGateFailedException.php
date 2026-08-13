<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Exception;

use Rick\Laravel\Application\Execution\Interface\StepFailureBase;
use Rick\Laravel\Application\Execution\Support\Quality\Result\QualityReport;
use RuntimeException;

final class QualityGateFailedException extends RuntimeException implements StepFailureBase
{
    public function __construct(public readonly QualityReport $report)
    {
        parent::__construct(sprintf(
            'Artifact [%s] failed quality gate [%s]: %s',
            $report->artifactKey,
            $report->ruleSetId,
            implode('; ', array_map(
                static fn ($violation): string => $violation->message,
                $report->violations(),
            )),
        ));
    }

    public function errorCode(): string
    {
        return 'quality_gate_failed';
    }
}
