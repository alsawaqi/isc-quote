<?php

use App\Services\QuotationDocumentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

// Read an exported snapshot and render preview files without touching quotations or their revisions.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.local.root' => storage_path('app/quotation-format-preview')]);
Storage::forgetDisk('local');
$snapshot = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$service = app(QuotationDocumentService::class);
$prefix = $argv[2] ?? 'sample';
if (! preg_match('/^[A-Za-z0-9_-]+$/', $prefix)) {
    throw new InvalidArgumentException('Preview name may contain only letters, numbers, underscores and hyphens.');
}
$service->writePdf($snapshot, $prefix.'-commercial.pdf');
$service->writeTechnicalPdf($snapshot, $prefix.'-technical.pdf');
$service->writeDocx($snapshot, $prefix.'-commercial.docx');
$service->writeTechnicalDocx($snapshot, $prefix.'-technical.docx');
echo storage_path('app/quotation-format-preview/'.$prefix).PHP_EOL;
