<?php

declare(strict_types=1);

namespace Rick\Stand\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ReflectionClass;
use ReflectionMethod;
use Rick\Laravel\Facade\Rick as RickFacade;
use Rick\Laravel\Rick;
use Rick\Stand\Console\Application as StandApplication;
use Rick\Stand\Fixture\CassetteCatalog;
use Rick\Stand\Fixture\CassetteLoader;
use Rick\Stand\Inventory\Element;
use Rick\Stand\Inventory\Inventory;
use Rick\Stand\Manifest\ScenarioManifest;
use Rick\Stand\Report\ReportWriter;
use Rick\Stand\Support\Snapshot;
use Rick\Stand\Tests\TestCase;
use RuntimeException;
use Throwable;

final class StandIntegrityTest extends TestCase
{
    public function test_mutation_report_keeps_a_safe_machine_readable_backlog(): void
    {
        $application = new StandApplication(dirname(__DIR__, 2));
        $command = (new ReflectionMethod($application, 'mutationCommand'))
            ->invoke($application, 'src/Domain');
        self::assertContains('--no-cache', $command);
        self::assertContains('--covered-only', $command);
        self::assertContains('--path=src/Domain', $command);

        $parser = new ReflectionMethod($application, 'mutationReport');
        $output = <<<'OUTPUT'
Duration: 0.40s
UNTESTED src/Domain/Run/CallBudget.php > Line 52: IncrementInteger - ID: aabbcc11
TIMEOUT src/Domain/Event/HasDeterministicEventId.php > Line 35: RemoveEarlyReturn - ID: ddeeff22
Mutations: 1 untested, 1 timeout, 8 tested
Score: 88.89%
Duration: 12.50s
Parallel: 8 processes
OUTPUT;

        $report = $parser->invoke($application, 'domain', 0, $output);
        self::assertSame(12.5, $report['duration_seconds']);
        self::assertSame(88.89, $report['score_percent']);
        self::assertSame('timeout', $report['issues'][0]['status']);
        self::assertSame('untested', $report['issues'][1]['status']);
        self::assertArrayNotHasKey('diff', $report['issues'][0]);

        $directory = sys_get_temp_dir().'/rick-stand-mutation-'.bin2hex(random_bytes(5));
        mkdir($directory);
        try {
            file_put_contents($directory.'/domain.json', json_encode($report, JSON_THROW_ON_ERROR));
            (new ReflectionMethod($application, 'writeMutationBacklog'))->invoke($application, $directory);
            $backlog = json_decode(
                (string) file_get_contents($directory.'/backlog.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertSame(2, $backlog['lanes']['domain']['issues_recorded']);
            self::assertSame(1, $backlog['lanes']['domain']['by_file']['src/Domain/Run/CallBudget.php']['untested']);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_manifest_inventory_and_fixtures_are_fail_closed(): void
    {
        $root = dirname(__DIR__, 2);
        $inventory = new Inventory;
        $catalog = new CassetteCatalog($root.'/fixtures');
        $manifest = new ScenarioManifest($root.'/scenarios.json');
        $manifest->validate($inventory, $catalog);

        $elements = $inventory->discover();
        self::assertCount(18, array_filter($elements, static fn ($element): bool => $element->category === 'public_api'));
        self::assertCount(20, array_filter($elements, static fn ($element): bool => $element->category === 'strategy'));
        self::assertCount(18, array_filter($elements, static fn ($element): bool => $element->category === 'use_case'));
        self::assertCount(8, array_filter($elements, static fn ($element): bool => $element->category === 'response_contract'));
        self::assertCount(3, array_filter($elements, static fn ($element): bool => $element->category === 'provider_outcome'));
        self::assertCount(11, array_filter($elements, static fn ($element): bool => $element->category === 'codec'));
        $public = array_values(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(Rick::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === Rick::class
                    && $method->getName() !== '__construct',
            ),
        ));
        preg_match_all(
            '/@method static [^ ]+ ([A-Za-z0-9_]+)\(/',
            (string) (new ReflectionClass(RickFacade::class))->getDocComment(),
            $matches,
        );
        $facade = $matches[1];
        sort($public);
        sort($facade);
        self::assertSame($public, $facade, 'The Facade must mirror the public Rick API exactly.');

        $this->assertUncoveredElementFails($root, $inventory, $catalog);
        $this->assertUnknownFixtureFails($root, $inventory, $catalog);
        $this->assertFutureAndUnsafeCassettesFail($root);
        $this->assertStrayHttpFails();
        $this->assertCanonicalReportsAreDeterministic($elements, $manifest);
    }

    private function assertUncoveredElementFails(string $root, Inventory $inventory, CassetteCatalog $catalog): void
    {
        $value = json_decode((string) file_get_contents($root.'/scenarios.json'), true, flags: JSON_THROW_ON_ERROR);
        array_pop($value['scenarios'][1]['covers']);
        $path = $this->temporaryJson($value);
        try {
            (new ScenarioManifest($path))->validate($inventory, $catalog);
            self::fail('An uncovered discovered element must fail manifest validation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('coverage is not exact', $error->getMessage());
        } finally {
            unlink($path);
        }
    }

    private function assertUnknownFixtureFails(string $root, Inventory $inventory, CassetteCatalog $catalog): void
    {
        $value = json_decode((string) file_get_contents($root.'/scenarios.json'), true, flags: JSON_THROW_ON_ERROR);
        $value['scenarios'][0]['fixtures'][] = 'fixture-does-not-exist';
        $path = $this->temporaryJson($value);
        try {
            (new ScenarioManifest($path))->validate($inventory, $catalog);
            self::fail('An unknown fixture must fail manifest validation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unknown fixture', $error->getMessage());
        } finally {
            unlink($path);
        }
    }

    private function assertFutureAndUnsafeCassettesFail(string $root): void
    {
        $loader = new CassetteLoader;
        $value = json_decode((string) file_get_contents($root.'/fixtures/synthetic-text-success.json'), true, flags: JSON_THROW_ON_ERROR);
        $value['schema_version'] = 2;
        $future = $this->temporaryJson($value);
        try {
            $loader->load($future);
            self::fail('A future cassette schema must fail.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Unsupported cassette schema', $error->getMessage());
        } finally {
            unlink($future);
        }

        $value['schema_version'] = 1;
        $value['outcome']['response']['text'] = 'Bearer definitely-not-safe';
        $unsafe = $this->temporaryJson($value);
        try {
            $loader->load($unsafe);
            self::fail('A cassette containing credentials must fail.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('credential', $error->getMessage());
        } finally {
            unlink($unsafe);
        }
    }

    private function assertStrayHttpFails(): void
    {
        try {
            Http::get('https://example.invalid/stand-must-not-connect');
            self::fail('A stray HTTP request must fail.');
        } catch (Throwable $error) {
            self::assertStringContainsString('without a matching fake', strtolower($error->getMessage()));
        }
    }

    /** @param list<Element> $elements */
    private function assertCanonicalReportsAreDeterministic(array $elements, ScenarioManifest $manifest): void
    {
        $base = sys_get_temp_dir().'/rick-stand-report-'.bin2hex(random_bytes(5));
        $first = $base.'-a';
        $second = $base.'-b';
        try {
            $writer = new ReportWriter;
            $fingerprintA = $writer->write($first, 'fast', 'source', $elements, $manifest->scenarios(), 0);
            $fingerprintB = $writer->write($second, 'fast', 'archive', $elements, $manifest->scenarios(), 0);
            self::assertSame($fingerprintA, $fingerprintB);
            try {
                Snapshot::assertMatches(['snapshot' => 'expected'], ['snapshot' => 'mismatch']);
                self::fail('A mismatched canonical snapshot must fail.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Snapshot mismatch', $error->getMessage());
            }
        } finally {
            $this->removeDirectory($first);
            $this->removeDirectory($second);
        }
    }

    /** @param array<string, mixed> $value */
    private function temporaryJson(array $value): string
    {
        $path = sys_get_temp_dir().'/rick-stand-self-test-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (! str_starts_with($directory, sys_get_temp_dir().'/rick-stand-report-') || ! is_dir($directory)) {
            return;
        }
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}
