<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Golden\Support;

/**
 * Characterization-snapshot assertion trait shared across unit and integration golden tests.
 */
trait MatchesGolden
{
    /**
     * Freezes output as a snapshot and asserts byte-identity on every later run (regenerate via UPDATE_GOLDEN=1).
     *
     * @param mixed $actual
     */
    protected function assertMatchesGolden($actual, string $name): void
    {
        $file = self::goldenDir() . '/' . $name . '.json';
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($json, "actual is not JSON-encodable for {$name}");

        if (!is_file($file) || getenv('UPDATE_GOLDEN')) {
            if (!is_dir(self::goldenDir())) {
                mkdir(self::goldenDir(), 0777, true);
            }
            file_put_contents($file, $json . "\n");
            self::markTestIncomplete("Golden {$name} generated — review the snapshot and re-run without UPDATE_GOLDEN.");
        }

        self::assertSame(rtrim((string) file_get_contents($file)), rtrim($json), "Golden mismatch: {$name} — a frozen boundary changed.");
    }

    private static function goldenDir(): string
    {
        return __DIR__ . '/../__snapshots__';
    }
}
