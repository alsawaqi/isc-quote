<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

final class DocumentBrandingAssets
{
    /**
     * @var array<string, string>
     */
    public const REQUIRED = [
        'header' => 'quotation-assets/isc-header.jpeg',
        'footer' => 'quotation-assets/isc-footer.jpeg',
        'abb' => 'quotation-assets/abb-value-provider.jpg',
        'stamp' => 'quotation-assets/isc-stamp.jpeg',
    ];

    public static function path(string $assetPath): ?string
    {
        $assetPath = self::normalizeRelativePath($assetPath);
        $bundledPath = resource_path($assetPath);

        if (is_file($bundledPath) && is_readable($bundledPath)) {
            return $bundledPath;
        }

        if (Storage::disk('local')->exists($assetPath)) {
            return Storage::disk('local')->path($assetPath);
        }

        return null;
    }

    public static function bundledPath(string $assetPath): string
    {
        return resource_path(self::normalizeRelativePath($assetPath));
    }

    public static function dataUri(string $assetPath): ?string
    {
        $path = self::path($assetPath);

        if ($path === null) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    private static function normalizeRelativePath(string $assetPath): string
    {
        $assetPath = str_replace('\\', '/', ltrim($assetPath, '/\\'));

        if (! str_starts_with($assetPath, 'quotation-assets/') || str_contains($assetPath, '..')) {
            throw new \InvalidArgumentException("Unsupported document branding asset path [{$assetPath}].");
        }

        return $assetPath;
    }
}
