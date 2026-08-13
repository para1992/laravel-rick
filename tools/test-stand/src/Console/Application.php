<?php

declare(strict_types=1);

namespace Rick\Stand\Console;

use Rick\Stand\Fixture\CassetteCatalog;
use Rick\Stand\Inventory\Inventory;
use Rick\Stand\Manifest\Scenario;
use Rick\Stand\Manifest\ScenarioManifest;
use Rick\Stand\Package\PackageLocator;
use Rick\Stand\Report\ReportWriter;
use RuntimeException;
use Throwable;

final class Application
{
    public function __construct(private readonly string $standRoot) {}

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $command = array_shift($arguments) ?? 'run';

        try {
            return match ($command) {
                'list' => $this->listing(),
                'run' => $this->runTests($arguments),
                'mutate' => $this->mutate($arguments),
                'help', '--help', '-h' => $this->help(),
                default => $this->unknown($command),
            };
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage()."\n");
            if ($command === 'run') {
                $reportRoot = dirname($this->standRoot, 2).'/build/test-stand/latest';
                (new ReportWriter)->write($reportRoot, 'invalid', 'source', [], [], 1, [$error->getMessage()]);
                fwrite(STDOUT, "Rick stand: FAIL\nReport: {$reportRoot}\n");
            }

            return 1;
        }
    }

    private function listing(): int
    {
        $inventory = new Inventory;
        $catalog = new CassetteCatalog($this->standRoot.'/fixtures');
        $manifest = new ScenarioManifest($this->standRoot.'/scenarios.json');
        $manifest->validate($inventory, $catalog);
        $scenarios = $manifest->scenarios();
        $fixtureLinks = [];
        foreach ($scenarios as $scenario) {
            foreach ($scenario->fixtures as $fixture) {
                $fixtureLinks[$fixture][] = $scenario->id;
            }
        }

        fwrite(STDOUT, sprintf("Elements: %d | Scenarios: %d | Fixtures: %d\n", count($inventory->discover()), count($scenarios), count($catalog->all())));
        foreach ($scenarios as $scenario) {
            fwrite(STDOUT, sprintf("%-30s lane=%-8s covers=%3d fixtures=%s\n", $scenario->id, $scenario->lane, count($scenario->covers), implode(',', $scenario->fixtures) ?: '-'));
        }
        foreach ($catalog->all() as $cassette) {
            fwrite(STDOUT, sprintf("fixture %-38s kind=%-14s scenarios=%s\n", $cassette->id, $cassette->kind, implode(',', $fixtureLinks[$cassette->id] ?? []) ?: '-'));
        }

        return 0;
    }

    /** @param list<string> $arguments */
    private function runTests(array $arguments): int
    {
        $options = $this->options($arguments);
        $lane = $options['lane'] ?? 'fast';
        $target = $options['target'] ?? 'source';
        if ($target === 'archive' && getenv('RICK_STAND_ARCHIVE_CHILD') !== '1') {
            return (new ArchiveRunner($this->standRoot, new ProcessRunner))->run($lane, $options['scenario'] ?? null);
        }
        if (! in_array($target, ['source', 'archive'], true)) {
            fwrite(STDERR, "Unknown target [{$target}].\n");

            return 2;
        }

        $reportRoot = getenv('RICK_STAND_REPORT_ROOT');
        if (! is_string($reportRoot) || $reportRoot === '') {
            $reportRoot = PackageLocator::root().'/build/test-stand/latest';
        }
        $inventory = new Inventory;
        $catalog = new CassetteCatalog($this->standRoot.'/fixtures');
        $manifest = new ScenarioManifest($this->standRoot.'/scenarios.json');
        $elements = [];
        $selected = [];
        $errors = [];
        $exitCode = 1;
        $scenarioExitCode = 1;

        try {
            $manifest->validate($inventory, $catalog);
            $elements = $inventory->discover();
            $selected = $this->select($manifest->scenarios(), $lane, $options['scenario'] ?? null);
            if ($selected === []) {
                throw new RuntimeException('No scenarios matched the requested selection.');
            }
            if (! is_dir($reportRoot) && ! mkdir($reportRoot, 0777, true) && ! is_dir($reportRoot)) {
                throw new RuntimeException("Unable to create report directory [{$reportRoot}].");
            }
            foreach (['junit.xml', 'coverage.xml', 'report.json', 'index.html'] as $old) {
                if (is_file($reportRoot.'/'.$old)) {
                    unlink($reportRoot.'/'.$old);
                }
            }
            $methods = array_map(
                static fn (Scenario $scenario): string => substr($scenario->test, (int) strrpos($scenario->test, '::') + 2),
                $selected,
            );
            $filter = count($methods) === 1
                ? $methods[0]
                : '/('.implode('|', array_map(static fn (string $method): string => preg_quote($method, '/'), $methods)).')/';
            $pestOverride = getenv('RICK_STAND_PEST');
            $pest = is_string($pestOverride) && is_file($pestOverride)
                ? $pestOverride
                : $this->standRoot.'/vendor/bin/pest';
            if (! is_file($pest)) {
                $pest = PackageLocator::root().'/vendor/bin/pest';
            }
            $command = [PHP_BINARY, $pest, '--configuration='.$this->standRoot.'/phpunit.xml', '--compact', '--log-junit='.$reportRoot.'/junit.xml', '--filter='.$filter];
            $exitCode = (new ProcessRunner)->run($command, $this->standRoot, [
                'RICK_STAND_LANE' => $lane,
                'RICK_STAND_TARGET' => $target,
            ]);
            $scenarioExitCode = $exitCode;
            if (is_file($reportRoot.'/junit.xml')) {
                $junit = (string) file_get_contents($reportRoot.'/junit.xml');
                if (preg_match('/skipped="([1-9][0-9]*)"/', $junit) === 1) {
                    $errors[] = 'Selected lane contains skipped tests.';
                    $exitCode = 1;
                }
            }
            if ($lane === 'full') {
                $qualityErrors = $this->fullQualityGateErrors(PackageLocator::root());
                $errors = [...$errors, ...$qualityErrors];
                if ($qualityErrors !== []) {
                    $exitCode = 1;
                }
            }
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
            fwrite(STDERR, $error->getMessage()."\n");
        }

        $fingerprint = (new ReportWriter)->write($reportRoot, $lane, $target, $elements, $selected, $exitCode, $errors, $scenarioExitCode);
        fwrite(STDOUT, sprintf("Rick stand: %s | lane=%s target=%s scenarios=%d fingerprint=%s\nReport: %s\n", $exitCode === 0 && $errors === [] ? 'PASS' : 'FAIL', $lane, $target, count($selected), $fingerprint, $reportRoot));

        return $exitCode === 0 && $errors === [] ? 0 : 1;
    }

    /** @param list<string> $arguments */
    private function mutate(array $arguments): int
    {
        $options = $this->options($arguments);
        $lane = $options['lane'] ?? null;
        $lanes = [
            'domain' => 'src/Domain',
            'execution' => 'src/Application/Execution',
            'persistence' => 'src/Infrastructure/Persistence',
        ];
        if (is_string($lane)) {
            $lanes = isset($lanes[$lane]) ? [$lane => $lanes[$lane]] : [];
        }
        if ($lanes === []) {
            fwrite(STDERR, "Unknown mutation lane. Expected domain, execution, or persistence.\n");

            return 2;
        }
        $reportRoot = PackageLocator::root().'/build/test-stand/latest/mutation';
        if (! is_dir($reportRoot)) {
            mkdir($reportRoot, 0777, true);
        }
        $overall = 0;
        foreach ($lanes as $name => $path) {
            $command = $this->mutationCommand($path);
            $process = (new ProcessRunner)->runCapturing($command, PackageLocator::root(), ['RICK_STAND_MUTATION_LANE' => $name]);
            $exit = $process['exit_code'];
            $report = $this->mutationReport($name, $exit, $process['output']);
            file_put_contents($reportRoot.'/'.$name.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            $this->writeMutationBacklog($reportRoot);
            if ($exit !== 0) {
                $overall = $exit;
            }
        }

        return $overall;
    }

    /** @return list<string> */
    private function mutationCommand(string $path): array
    {
        return [
            PackageLocator::root().'/vendor/bin/pest',
            '--mutate',
            '--parallel',
            '--covered-only',
            '--no-cache',
            '--min=85',
            '--path='.$path,
            '--compact',
        ];
    }

    /** @return array<string, mixed> */
    private function mutationReport(string $lane, int $exitCode, string $output): array
    {
        $plain = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $output) ?? $output;
        preg_match('/([0-9]+) Mutations for ([0-9]+) Files created/', $plain, $created);
        preg_match('/Mutations:\s*(?:([0-9]+) untested,\s*)?(?:([0-9]+) uncovered,\s*)?(?:([0-9]+) pending,\s*)?(?:([0-9]+) timeout,\s*)?([0-9]+) tested/', $plain, $counts);
        preg_match('/Score:\s*([0-9.]+)%/', $plain, $score);
        preg_match_all('/Duration:\s*([0-9.]+)s/', $plain, $durations);
        preg_match('/Parallel:\s*([0-9]+) processes/', $plain, $parallel);
        preg_match_all(
            '/^\s*(UNTESTED|TIMEOUT)\s+([^\r\n]+?)\s+>\s+Line\s+([0-9]+):\s+([^\r\n]+?)\s+-\s+ID:\s+([a-f0-9]+)\s*$/mi',
            $plain,
            $issueMatches,
            PREG_SET_ORDER,
        );
        $issues = array_map(static fn (array $match): array => [
            'status' => strtolower($match[1]),
            'file' => trim($match[2]),
            'line' => (int) $match[3],
            'mutator' => trim($match[4]),
            'id' => $match[5],
        ], $issueMatches);
        usort($issues, static fn (array $left, array $right): int => [
            $left['file'],
            $left['line'],
            $left['mutator'],
            $left['id'],
        ] <=> [
            $right['file'],
            $right['line'],
            $right['mutator'],
            $right['id'],
        ]);
        $durationValues = $durations[1] ?? [];
        $duration = $durationValues === [] ? null : (float) end($durationValues);

        return [
            'schema_version' => 1,
            'lane' => $lane,
            'mutations' => isset($created[1]) ? (int) $created[1] : null,
            'files' => isset($created[2]) ? (int) $created[2] : null,
            'untested' => isset($counts[1]) && $counts[1] !== '' ? (int) $counts[1] : 0,
            'uncovered' => isset($counts[2]) && $counts[2] !== '' ? (int) $counts[2] : 0,
            'pending' => isset($counts[3]) && $counts[3] !== '' ? (int) $counts[3] : 0,
            'timeouts' => isset($counts[4]) && $counts[4] !== '' ? (int) $counts[4] : 0,
            'tested' => isset($counts[5]) ? (int) $counts[5] : null,
            'score_percent' => isset($score[1]) ? (float) $score[1] : null,
            'required_percent' => 85.0,
            'duration_seconds' => $duration,
            'processes' => isset($parallel[1]) ? (int) $parallel[1] : null,
            'issues' => $issues,
            'output_sha256' => hash('sha256', $plain),
            'exit_code' => $exitCode,
        ];
    }

    private function writeMutationBacklog(string $reportRoot): void
    {
        $lanes = [];
        foreach (['domain', 'execution', 'persistence'] as $lane) {
            $path = $reportRoot.'/'.$lane.'.json';
            if (! is_file($path)) {
                continue;
            }
            $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $byFile = [];
            foreach ($report['issues'] ?? [] as $issue) {
                if (! is_array($issue) || ! is_string($issue['file'] ?? null)) {
                    continue;
                }
                $status = is_string($issue['status'] ?? null) ? $issue['status'] : 'unknown';
                $byFile[$issue['file']][$status] = ($byFile[$issue['file']][$status] ?? 0) + 1;
            }
            ksort($byFile);
            $lanes[$lane] = [
                'score_percent' => $report['score_percent'] ?? null,
                'required_percent' => $report['required_percent'] ?? 85.0,
                'exit_code' => $report['exit_code'] ?? 1,
                'untested' => $report['untested'] ?? 0,
                'timeouts' => $report['timeouts'] ?? 0,
                'issues_recorded' => count($report['issues'] ?? []),
                'by_file' => $byFile,
            ];
        }

        file_put_contents(
            $reportRoot.'/backlog.json',
            json_encode([
                'schema_version' => 1,
                'source' => 'pest-mutate-output',
                'lanes' => $lanes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    }

    /** @return list<string> */
    private function fullQualityGateErrors(string $packageRoot): array
    {
        $errors = [];
        $coverage = $packageRoot.'/build/coverage.xml';
        if (! is_file($coverage)) {
            $errors[] = 'Coverage evidence is missing.';
        } else {
            $contents = (string) file_get_contents($coverage);
            preg_match_all('/<metrics\b([^>]*)\/?>/', $contents, $matches);
            $totals = ['statements' => 0, 'coveredstatements' => 0, 'methods' => 0, 'coveredmethods' => 0];
            foreach ($matches[1] ?? [] as $attributes) {
                foreach (array_keys($totals) as $name) {
                    if (preg_match('/\b'.preg_quote($name, '/').'="([0-9]+)"/', $attributes, $value) === 1) {
                        $totals[$name] = max($totals[$name], (int) $value[1]);
                    }
                }
            }
            if ($totals['statements'] < 1 || $totals['methods'] < 1) {
                $errors[] = 'Coverage evidence does not contain aggregate metrics.';
            } else {
                $statements = 100 * $totals['coveredstatements'] / $totals['statements'];
                $methods = 100 * $totals['coveredmethods'] / $totals['methods'];
                if ($statements + 0.00001 < 90 || $methods + 0.00001 < 80) {
                    $errors[] = sprintf('Coverage gate failed: %.2f%% statements and %.2f%% methods; required 90.00%% and 80.00%%.', $statements, $methods);
                }
            }
        }

        return $errors;
    }

    /** @param list<Scenario> $scenarios @return list<Scenario> */
    private function select(array $scenarios, string $lane, ?string $id): array
    {
        if ($id !== null) {
            return array_values(array_filter($scenarios, static fn (Scenario $scenario): bool => $scenario->id === $id));
        }
        $lanes = match ($lane) {
            'fast' => ['fast'],
            'full' => ['fast', 'full'],
            'archive' => ['fast', 'archive'],
            default => throw new RuntimeException("Unknown lane [{$lane}]."),
        };

        return array_values(array_filter($scenarios, static fn (Scenario $scenario): bool => in_array($scenario->lane, $lanes, true)));
    }

    /** @param list<string> $arguments @return array<string, string> */
    private function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)=(.+)$/', $argument, $matches) !== 1) {
                throw new RuntimeException("Invalid option [{$argument}].");
            }
            $options[$matches[1]] = $matches[2];
        }

        return $options;
    }

    private function help(): int
    {
        fwrite(STDOUT, "rick-stand list\nrick-stand run [--scenario=ID] [--lane=fast|full] [--target=source|archive]\nrick-stand mutate [--lane=domain|execution|persistence]\n");

        return 0;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "Unknown command [{$command}].\n");

        return 2;
    }
}
