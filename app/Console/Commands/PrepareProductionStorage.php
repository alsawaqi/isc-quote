<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PrepareProductionStorage extends Command
{
    protected $signature = 'app:prepare-storage';

    protected $description = 'Create storage and cache directories needed on shared hosting without requiring symlinks.';

    public function handle(): int
    {
        $directories = [
            storage_path('app/private'),
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

        $this->info('Storage directories are ready. The public storage symlink was not created because this app serves uploaded/generated documents through authenticated download routes.');

        return self::SUCCESS;
    }
}
