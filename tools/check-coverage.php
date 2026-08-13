<?php

declare(strict_types=1);

$path = $argv[1] ?? null;
$statementMinimum = isset($argv[2]) ? (float) $argv[2] : 90.0;
$methodMinimum = isset($argv[3]) ? (float) $argv[3] : 80.0;
if (! is_string($path) || ! is_file($path)) {
    throw new InvalidArgumentException('Coverage XML path is missing or unreadable.');
}

$contents = file_get_contents($path);
if ($contents === false || preg_match_all('/<metrics\b([^>]*)\/?>/', $contents, $matches) === false) {
    throw new RuntimeException('Coverage XML cannot be read.');
}

$totals = ['statements' => 0, 'coveredstatements' => 0, 'methods' => 0, 'coveredmethods' => 0];
foreach ($matches[1] as $attributes) {
    foreach (array_keys($totals) as $name) {
        if (preg_match('/\b'.preg_quote($name, '/').'="([0-9]+)"/', $attributes, $value) === 1) {
            $totals[$name] = max($totals[$name], (int) $value[1]);
        }
    }
}
if ($totals['statements'] < 1 || $totals['methods'] < 1) {
    throw new RuntimeException('Coverage XML does not contain aggregate statement and method metrics.');
}

$statements = 100 * $totals['coveredstatements'] / $totals['statements'];
$methods = 100 * $totals['coveredmethods'] / $totals['methods'];
fwrite(STDOUT, sprintf("Coverage: %.2f%% statements, %.2f%% methods.\n", $statements, $methods));
if ($statements + 0.00001 < $statementMinimum || $methods + 0.00001 < $methodMinimum) {
    throw new RuntimeException(sprintf(
        'Coverage gate failed; required %.2f%% statements and %.2f%% methods.',
        $statementMinimum,
        $methodMinimum,
    ));
}
