<?php

declare(strict_types=1);

namespace Rick\Stand\Manifest;

use InvalidArgumentException;
use Rick\Stand\Fixture\CassetteCatalog;
use Rick\Stand\Inventory\Inventory;
use Rick\Stand\Support\StrictJson;
use RuntimeException;

final class ScenarioManifest
{
    public function __construct(private readonly string $path) {}

    /** @return list<Scenario> */
    public function scenarios(): array
    {
        $manifest = StrictJson::file($this->path);
        StrictJson::keys($manifest, ['schema_version', 'scenarios'], 'scenario manifest');
        if (($manifest['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported scenario manifest schema version.');
        }

        $scenarios = [];
        foreach (StrictJson::list($manifest['scenarios'] ?? null, 'scenarios') as $index => $raw) {
            $value = StrictJson::object($raw, "scenarios.{$index}");
            StrictJson::keys($value, ['id', 'lane', 'covers', 'fixtures', 'test'], "scenarios.{$index}");
            $covers = array_map(
                static fn (mixed $id): string => StrictJson::string($id, "scenarios.{$index}.covers"),
                StrictJson::list($value['covers'] ?? null, "scenarios.{$index}.covers"),
            );
            $fixtures = array_map(
                static fn (mixed $id): string => StrictJson::string($id, "scenarios.{$index}.fixtures"),
                StrictJson::list($value['fixtures'] ?? null, "scenarios.{$index}.fixtures"),
            );
            $scenarios[] = new Scenario(
                StrictJson::string($value['id'] ?? null, "scenarios.{$index}.id"),
                StrictJson::string($value['lane'] ?? null, "scenarios.{$index}.lane"),
                $covers,
                $fixtures,
                StrictJson::string($value['test'] ?? null, "scenarios.{$index}.test"),
            );
        }

        return $scenarios;
    }

    public function validate(Inventory $inventory, CassetteCatalog $fixtures): void
    {
        $scenarios = $this->scenarios();
        $ids = array_map(static fn (Scenario $scenario): string => $scenario->id, $scenarios);
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Scenario IDs must be unique.');
        }

        $known = array_keys($inventory->indexed());
        $covered = [];
        foreach ($scenarios as $scenario) {
            if (! in_array($scenario->lane, ['fast', 'full', 'archive', 'mutation'], true)) {
                throw new RuntimeException("Scenario [{$scenario->id}] has unknown lane [{$scenario->lane}].");
            }
            if (preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*::test_[A-Za-z0-9_]+$/', $scenario->test) !== 1) {
                throw new RuntimeException("Scenario [{$scenario->id}] has an invalid test method.");
            }
            [$class, $method] = explode('::', $scenario->test, 2);
            if (! class_exists($class) || ! method_exists($class, $method)) {
                throw new RuntimeException("Scenario [{$scenario->id}] references missing test [{$scenario->test}].");
            }
            foreach ($scenario->covers as $element) {
                if (! in_array($element, $known, true)) {
                    throw new RuntimeException("Scenario [{$scenario->id}] covers unknown element [{$element}].");
                }
                $covered[] = $element;
            }
            foreach ($scenario->fixtures as $fixture) {
                if (! $fixtures->has($fixture)) {
                    throw new RuntimeException("Scenario [{$scenario->id}] references unknown fixture [{$fixture}].");
                }
            }
        }

        $covered = array_values(array_unique($covered));
        sort($covered);
        sort($known);
        if ($covered !== $known) {
            $missing = array_values(array_diff($known, $covered));
            $removed = array_values(array_diff($covered, $known));
            throw new RuntimeException(sprintf(
                'Scenario coverage is not exact. Missing: [%s]. Unknown: [%s].',
                implode(', ', $missing),
                implode(', ', $removed),
            ));
        }
    }
}
