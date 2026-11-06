<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use SiteBuilder\Core\Content\CanonicalJson;

/** Retains the distinction between JSON objects, lists and null. */
final class JsonValueCast implements CastsAttributes, ComparesCastableAttributes
{
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        $decode = fn ($value) => is_string($value) ? json_decode($value, flags: JSON_THROW_ON_ERROR) : $value;

        return app(CanonicalJson::class)->equals($decode($firstValue), $decode($secondValue));
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : json_decode($value, flags: JSON_THROW_ON_ERROR);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
