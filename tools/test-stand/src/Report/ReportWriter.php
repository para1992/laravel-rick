<?php

declare(strict_types=1);

namespace Rick\Stand\Report;

use Rick\Stand\Inventory\Element;
use Rick\Stand\Manifest\Scenario;
use Rick\Stand\Package\PackageLocator;
use Rick\Stand\Support\CanonicalJson;
use RuntimeException;

final class ReportWriter
{
    /** @param list<Element> $elements @param list<Scenario> $scenarios @param list<string> $errors */
    public function write(
        string $directory,
        string $lane,
        string $target,
        array $elements,
        array $scenarios,
        int $exitCode,
        array $errors = [],
        ?int $scenarioExitCode = null,
    ): string {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create report directory [{$directory}].");
        }

        $status = $exitCode === 0 && $errors === [] ? 'passed' : 'failed';
        $scenarioStatus = ($scenarioExitCode ?? $exitCode) === 0 ? 'passed' : 'failed';
        $scenarioRows = array_map(
            static fn (Scenario $scenario): array => [
                'id' => $scenario->id,
                'lane' => $scenario->lane,
                'test' => $scenario->test,
                'fixtures' => $scenario->fixtures,
                'status' => $scenarioStatus,
            ],
            $scenarios,
        );
        $selected = [];
        $elementFixtures = [];
        foreach ($scenarios as $scenario) {
            foreach ($scenario->covers as $id) {
                $selected[$id][] = $scenario->id;
                $elementFixtures[$id] = array_values(array_unique(array_merge(
                    $elementFixtures[$id] ?? [],
                    $scenario->fixtures,
                )));
            }
        }
        $matrix = [];
        foreach ($elements as $element) {
            $matrix[] = [
                'element' => $element->id,
                'category' => $element->category,
                'scenarios' => $selected[$element->id] ?? [],
                'fixtures' => $elementFixtures[$element->id] ?? [],
                'status' => isset($selected[$element->id]) ? $scenarioStatus : 'not_selected',
            ];
        }
        $fingerprintPayload = [
            'schema_version' => 1,
            'lane' => $lane,
            'elements' => array_map(static fn (Element $element): array => $element->toArray(), $elements),
            'scenarios' => $scenarioRows,
            'matrix' => $matrix,
            'errors' => $this->sanitize($errors),
        ];
        $fingerprint = hash('sha256', CanonicalJson::encode($fingerprintPayload));
        $coverage = $this->coverageMetrics(PackageLocator::root().'/build/coverage.xml');
        $mutations = $this->mutationMetrics(PackageLocator::root().'/build/test-stand/latest/mutation');
        $report = [
            'schema_version' => 1,
            'generated_at' => gmdate(DATE_ATOM),
            'lane' => $lane,
            'target' => $target,
            'status' => $status,
            'exit_code' => $exitCode,
            'canonical_fingerprint' => $fingerprint,
            'quality_gates' => [
                'element_coverage_percent' => $matrix === [] ? 100 : round(100 * count(array_filter($matrix, static fn (array $row): bool => $row['status'] === 'passed')) / count($matrix), 2),
                'statement_coverage_required_percent' => 90,
                'method_coverage_required_percent' => 80,
                'mutation_score_required_percent' => 85,
                'statement_coverage_percent' => $coverage['statements_percent'] ?? null,
                'method_coverage_percent' => $coverage['methods_percent'] ?? null,
                'coverage_passed' => isset($coverage['statements_percent'], $coverage['methods_percent'])
                    && $coverage['statements_percent'] + 0.00001 >= 90
                    && $coverage['methods_percent'] + 0.00001 >= 80,
                'mutation_lanes' => $mutations,
                'skipped_tests_allowed' => 0,
                'network_llm_calls_allowed' => 0,
            ],
            'scenarios' => $scenarioRows,
            'matrix' => $matrix,
            'errors' => $this->sanitize($errors),
        ];
        file_put_contents(
            $directory.'/report.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
        );
        $this->writeHtml($directory.'/index.html', $report);
        if (! is_file($directory.'/junit.xml')) {
            file_put_contents(
                $directory.'/junit.xml',
                sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\"?><testsuites tests=\"1\" failures=\"%d\"><testsuite name=\"rick-stand\"><testcase name=\"bootstrap\">%s</testcase></testsuite></testsuites>\n", $status === 'failed' ? 1 : 0, $status === 'failed' ? '<failure message="stand bootstrap failed"/>' : ''),
            );
        }
        if (! is_file($directory.'/coverage.xml')) {
            $sourceCoverage = PackageLocator::root().'/build/coverage.xml';
            if (is_file($sourceCoverage)) {
                copy($sourceCoverage, $directory.'/coverage.xml');
            } else {
                file_put_contents(
                    $directory.'/coverage.xml',
                    "<?xml version=\"1.0\" encoding=\"UTF-8\"?><coverage generated=\"0\" available=\"false\"><project/></coverage>\n",
                );
            }
        }
        file_put_contents($directory.'/inventory-diff.json', "{\n    \"schema_version\": 1,\n    \"missing\": [],\n    \"unknown\": []\n}\n");
        file_put_contents($directory.'/snapshot.json', json_encode(['schema_version' => 1, 'fingerprint' => $fingerprint, 'status' => $status], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        file_put_contents($directory.'/timeline.json', json_encode(['schema_version' => 1, 'events' => array_map(static fn (Scenario $scenario): array => ['scenario' => $scenario->id, 'status' => $status], $scenarios)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        file_put_contents($directory.'/metrics.json', json_encode(['schema_version' => 1, 'elements' => count($elements), 'scenarios' => count($scenarios), 'failed' => $status === 'failed' ? 1 : 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

        return $fingerprint;
    }

    /** @return array{statements_percent?: float, methods_percent?: float} */
    private function coverageMetrics(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $contents = (string) file_get_contents($path);
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
            return [];
        }

        return [
            'statements_percent' => round(100 * $totals['coveredstatements'] / $totals['statements'], 2),
            'methods_percent' => round(100 * $totals['coveredmethods'] / $totals['methods'], 2),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function mutationMetrics(string $directory): array
    {
        $metrics = [];
        foreach (['domain', 'execution', 'persistence'] as $lane) {
            $path = $directory.'/'.$lane.'.json';
            if (! is_file($path)) {
                $metrics[$lane] = ['available' => false, 'passed' => false];

                continue;
            }
            $result = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $metrics[$lane] = [
                'available' => true,
                'passed' => ($result['exit_code'] ?? 1) === 0,
                'score_percent' => $result['score_percent'] ?? null,
                'exit_code' => $result['exit_code'] ?? 1,
            ];
        }

        return $metrics;
    }

    /** @param array<string, mixed> $report */
    private function writeHtml(string $path, array $report): void
    {
        $rows = '';
        foreach ($report['matrix'] as $row) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars($row['element'], ENT_QUOTES),
                htmlspecialchars($row['category'], ENT_QUOTES),
                htmlspecialchars(implode(', ', $row['scenarios']), ENT_QUOTES),
                htmlspecialchars(implode(', ', $row['fixtures']), ENT_QUOTES),
                htmlspecialchars($row['status'], ENT_QUOTES),
            );
        }
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Laravel Rick test stand</title>'
            .'<style>body{font:14px system-ui;margin:2rem;color:#17202a}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccd1d1;padding:.45rem;text-align:left}th{background:#f4f6f7}code{word-break:break-all}</style>'
            .'</head><body><h1>Laravel Rick test stand</h1><p>Status: <strong>'.htmlspecialchars($report['status'], ENT_QUOTES).'</strong></p>'
            .'<p>Fingerprint: <code>'.htmlspecialchars($report['canonical_fingerprint'], ENT_QUOTES).'</code></p>'
            .'<table><thead><tr><th>Element</th><th>Category</th><th>Scenario</th><th>Fixture</th><th>Result</th></tr></thead><tbody>'.$rows.'</tbody></table></body></html>';
        file_put_contents($path, $html);
    }

    /** @param list<string> $messages @return list<string> */
    private function sanitize(array $messages): array
    {
        return array_map(
            static function (string $message): string {
                $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
                $message = preg_replace('/(?:sk-|AIza)[A-Za-z0-9_-]{8,}/', '[redacted]', $message) ?? $message;

                return mb_substr($message, 0, 2000);
            },
            $messages,
        );
    }
}
