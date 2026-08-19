<?php

namespace App\Console\Commands;

use App\Services\DocumentBrandingAssets;
use Illuminate\Console\Command;

class PrepareProductionStorage extends Command
{
    protected $signature = 'app:prepare-storage';

    protected $description = 'Create storage and cache directories needed on shared hosting without requiring symlinks.';

    public function handle(): int
    {
        $directories = [
            (string) config('filesystems.disks.local.root'),
            storage_path('app/public'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        foreach ($directories as $directory) {
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                $this->error("Could not create directory: {$directory}");

                return self::FAILURE;
            }

            @chmod($directory, 0775);
            $this->line("Ready: {$directory}");
        }

        foreach (DocumentBrandingAssets::REQUIRED as $name => $assetPath) {
            $bundledPath = DocumentBrandingAssets::bundledPath($assetPath);

            if (! is_file($bundledPath) || ! is_readable($bundledPath)) {
                $this->error("Missing bundled quotation branding asset [{$name}]: {$bundledPath}");

                return self::FAILURE;
            }

            $this->line("Bundled branding asset ready: {$bundledPath}");
        }

        $this->info('Storage directories and bundled quotation branding assets are ready. The public storage symlink was not created because this app serves uploaded/generated documents through authenticated download routes.');

        return self::SUCCESS;
    }
}
