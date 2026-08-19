<?php

namespace App\Casts;

use App\Services\RichTextSanitizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<?string, ?string> */
final class SanitizedRichText implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return app(RichTextSanitizer::class)->sanitize(is_string($value) ? $value : null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return app(RichTextSanitizer::class)->sanitize(is_string($value) ? $value : null);
    }
}
