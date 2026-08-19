<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    private static ?string $testFilesystemRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $configuredRoot = $this->normalizeFilesystemPath((string) config('filesystems.disks.local.root'));
        $privateRoot = $this->normalizeFilesystemPath(storage_path('app/private'));
        $testingRoot = $this->normalizeFilesystemPath(storage_path('framework/testing/disks'));

        if ($configuredRoot === $privateRoot || ! str_starts_with($configuredRoot, $testingRoot.'/')) {
            throw new \RuntimeException(
                "Refusing to run PHPUnit with the local disk rooted at [{$configuredRoot}]. "
                ."The test disk must be inside [{$testingRoot}]."
            );
        }

        self::$testFilesystemRoot ??= storage_path(
            'framework/testing/disks/local-'.getmypid().'-'.bin2hex(random_bytes(6))
        );

        config(['filesystems.disks.local.root' => self::$testFilesystemRoot]);
        Storage::forgetDisk('local');
    }

    private function normalizeFilesystemPath(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }
}
