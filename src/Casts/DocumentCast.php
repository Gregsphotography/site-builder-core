<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;

final class DocumentCast implements CastsAttributes, ComparesCastableAttributes
{
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        $decode = fn ($value) => is_string($value) ? json_decode($value, flags: JSON_THROW_ON_ERROR) : $value;

        return app(CanonicalJson::class)->equals($decode($firstValue), $decode($secondValue));
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Document
    {
        return $value === null ? null : app(DocumentCodec::class)->decode($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! $value instanceof Document) {
            throw new InvalidArgumentException('Content columns require a Document or null.');
        }

        return app(DocumentCodec::class)->encode($value);
    }
}
