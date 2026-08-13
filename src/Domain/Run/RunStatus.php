<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

enum RunStatus: string
{
    case Created = 'created';
    case Running = 'running';
    case AwaitingInput = 'awaiting_input';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Failed,
            self::Cancelled,
        ], true);
    }
}
