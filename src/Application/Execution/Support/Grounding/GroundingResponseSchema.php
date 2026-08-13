<?php

declare(strict_types=1);

namespace Rick\Laravel\Application\Execution\Support\Grounding;

use LogicException;

final readonly class GroundingResponseSchema
{
    /**
     * @param  array<string, mixed>  $base
     * @param  non-empty-list<string>  $unitIds
     * @param  list<string>  $evidenceKeys
     * @return array<string, mixed>
     */
    public static function forBatch(array $base, array $unitIds, array $evidenceKeys): array
    {
        $properties = self::map($base['properties'] ?? null, 'properties');
        $claims = self::map($properties['claims'] ?? null, 'properties.claims');
        $claim = self::map($claims['items'] ?? null, 'properties.claims.items');
        $claimProperties = self::map(
            $claim['properties'] ?? null,
            'properties.claims.items.properties',
        );
        $unitId = self::map(
            $claimProperties['unit_id'] ?? null,
            'properties.claims.items.properties.unit_id',
        );
        $evidence = self::map(
            $claimProperties['evidence'] ?? null,
            'properties.claims.items.properties.evidence',
        );
        $reference = self::map(
            $evidence['items'] ?? null,
            'properties.claims.items.properties.evidence.items',
        );
        $referenceProperties = self::map(
            $reference['properties'] ?? null,
            'properties.claims.items.properties.evidence.items.properties',
        );
        $artifactKey = self::map(
            $referenceProperties['artifact_key'] ?? null,
            'properties.claims.items.properties.evidence.items.properties.artifact_key',
        );

        $unitId['enum'] = $unitIds;
        $claimProperties['unit_id'] = $unitId;
        if ($evidenceKeys === []) {
            $evidence['maxItems'] = 0;
        } else {
            $artifactKey['enum'] = $evidenceKeys;
            $referenceProperties['artifact_key'] = $artifactKey;
            $reference['properties'] = $referenceProperties;
            $evidence['items'] = $reference;
        }
        $claimProperties['evidence'] = $evidence;
        $claim['properties'] = $claimProperties;
        $claims['items'] = $claim;
        $claims['minItems'] = count($unitIds);
        $claims['maxItems'] = count($unitIds);
        $properties['claims'] = $claims;
        $base['properties'] = $properties;

        return $base;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new LogicException(
                "Grounded verification output schema is missing object [{$path}].",
            );
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new LogicException(
                    "Grounded verification output schema object [{$path}] has a non-string key.",
                );
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
