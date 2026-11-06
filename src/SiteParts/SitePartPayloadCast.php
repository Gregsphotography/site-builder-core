<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use SiteBuilder\Core\Content\CanonicalJson;

final class SitePartPayloadCast implements CastsAttributes, ComparesCastableAttributes
{
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        $decode = fn ($value) => is_string($value) ? json_decode($value, flags: JSON_THROW_ON_ERROR) : $value;

        return app(CanonicalJson::class)->equals($decode($firstValue), $decode($secondValue));
    }

    public function get($model, string $key, mixed $value, array $attributes): ?SitePartPayload
    {
        return $value === null ? null : SitePartPayload::decode($value);
    }

    public function set($model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value !== null && ! $value instanceof SitePartPayload) {
            throw new InvalidArgumentException('Site-part persistence requires a typed payload.');
        }

        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
