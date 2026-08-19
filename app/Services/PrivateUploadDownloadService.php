<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PrivateUploadDownloadService
{
    public function download(?string $storedPath, ?string $originalFileName, string $expectedDirectory): BinaryFileResponse
    {
        $path = str_replace('\\', '/', trim((string) $storedPath));
        $directory = trim(str_replace('\\', '/', $expectedDirectory), '/');
        $segments = explode('/', $path);

        if (
            $path === ''
            || $directory === ''
            || str_contains($path, "\0")
            || str_contains($path, ':')
            || str_starts_with($path, '/')
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || str_replace('\\', '/', dirname($path)) !== $directory
            || ! Storage::disk('local')->exists($path)
        ) {
            abort(404);
        }

        $absolutePath = realpath(Storage::disk('local')->path($path));
        $diskRoot = realpath((string) config('filesystems.disks.local.root'));

        if ($absolutePath === false || $diskRoot === false || ! is_file($absolutePath)) {
            abort(404);
        }

        $normalizedFile = strtolower(str_replace('\\', '/', $absolutePath));
        $normalizedRoot = rtrim(strtolower(str_replace('\\', '/', $diskRoot)), '/').'/';

        if (! str_starts_with($normalizedFile, $normalizedRoot)) {
            abort(404);
        }

        $downloadName = Str::upper($this->safeDownloadName($originalFileName, basename($path)));

        return response()->download($absolutePath, $downloadName, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeDownloadName(?string $originalFileName, string $fallback): string
    {
        $name = basename(str_replace('\\', '/', (string) $originalFileName));
        $name = trim(str_replace(["\r", "\n", "\0"], '', Str::ascii($name)));
        $name = preg_replace('/[^A-Za-z0-9._() -]+/', '_', $name) ?: '';

        if ($name === '' || in_array($name, ['.', '..'], true)) {
            return $fallback;
        }

        return $name;
    }
}
