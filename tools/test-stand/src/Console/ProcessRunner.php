<?php

declare(strict_types=1);

namespace Rick\Stand\Console;

use RuntimeException;

final class ProcessRunner
{
    /** @param list<string> $command @param array<string, string> $environment */
    public function run(array $command, string $directory, array $environment = []): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory, $environment + $this->environment());
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start command: '.implode(' ', $command));
        }

        return proc_close($process);
    }

    /** @param list<string> $command @param array<string, string> $environment @return array{exit_code: int, output: string} */
    public function runCapturing(array $command, string $directory, array $environment = []): array
    {
        $process = proc_open($command, [STDIN, ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory, $environment + $this->environment());
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start command: '.implode(' ', $command));
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $exitCode = null;
        do {
            foreach ([1 => STDOUT, 2 => STDERR] as $index => $destination) {
                $chunk = stream_get_contents($pipes[$index]);
                if (is_string($chunk) && $chunk !== '') {
                    fwrite($destination, $chunk);
                    $output .= $chunk;
                }
            }
            $status = proc_get_status($process);
            if (! $status['running'] && $status['exitcode'] >= 0) {
                $exitCode = $status['exitcode'];
            }
            if ($status['running']) {
                usleep(10_000);
            }
        } while ($status['running']);
        foreach ([1 => STDOUT, 2 => STDERR] as $index => $destination) {
            $chunk = stream_get_contents($pipes[$index]);
            if (is_string($chunk) && $chunk !== '') {
                fwrite($destination, $chunk);
                $output .= $chunk;
            }
            fclose($pipes[$index]);
        }
        $closed = proc_close($process);

        return ['exit_code' => $exitCode ?? $closed, 'output' => $output];
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $environment = [];
        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }
}
