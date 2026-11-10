<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use Illuminate\Http\Request;

/** Bounded GET state; only PageController admits it into public rendering. */
final readonly class PublicQuery
{
    public function __construct(private array $values = []) {}

    public static function fromRequest(Request $request): self
    {
        $values = [];
        foreach (array_slice($request->query(), 0, 100, true) as $key => $value) {
            if (is_string($key) && preg_match('/^(?:q|page|type|tag|source|sb_[a-f0-9]{16}_(?:q|page|type|tag))$/D', $key) && is_scalar($value)) {
                $values[$key] = mb_substr((string) $value, 0, $key === 'type' || str_ends_with($key, '_type') ? 255 : 200);
            }
        }

        return new self($values);
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->values;
    }
}
