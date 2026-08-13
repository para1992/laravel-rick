<?php

declare(strict_types=1);

namespace Rick\Stand\Support;

use RuntimeException;

final class Snapshot
{
    public static function assertMatches(mixed $expected, mixed $actual): void
    {
        $expectedFingerprint = hash('sha256', CanonicalJson::encode($expected));
        $actualFingerprint = hash('sha256', CanonicalJson::encode($actual));
        if (! hash_equals($expectedFingerprint, $actualFingerprint)) {
            throw new RuntimeException(sprintf(
                'Snapshot mismatch: expected fingerprint [%s], actual fingerprint [%s].',
                $expectedFingerprint,
                $actualFingerprint,
            ));
        }
    }
}
