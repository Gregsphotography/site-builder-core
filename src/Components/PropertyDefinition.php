<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Components;

use InvalidArgumentException;

final readonly class PropertyDefinition
{
    /** @param list<string> $rules @param list<string> $options @param array<string, self> $properties */
    public function __construct(
        public string $kind,
        public bool $translated = false,
        public bool $nullable = false,
        public array $rules = [],
        public array $options = [],
        public bool $requiredForPublish = false,
        public array $properties = [],
        public ?self $items = null,
        public string $mediaKind = 'image',
        public ?string $revisionKind = null,
    ) {
        if (! in_array($kind, ['text', 'html', 'boolean', 'number', 'enum', 'token', 'url', 'media', 'page', 'revision', 'object', 'list'], true)) {
            throw new InvalidArgumentException('Unknown property kind.');
        }
        if (! in_array($mediaKind, ['image', 'video', 'track'], true)) {
            throw new InvalidArgumentException('Unknown media kind.');
        }
        if ($revisionKind !== null && ! in_array($revisionKind, ['section', 'header', 'footer', 'design', 'overlay'], true)) {
            throw new InvalidArgumentException('Unknown revision resource kind.');
        }
        foreach ($properties as $key => $definition) {
            if (! is_string($key) || ! $definition instanceof self) {
                throw new InvalidArgumentException('Object properties must be named property definitions.');
            }
        }
        if ($kind === 'list' && $items === null) {
            throw new InvalidArgumentException('List properties require an item definition.');
        }
    }

    /** @param list<string> $rules */
    public static function text(bool $translated = false, array $rules = [], bool $nullable = false, bool $requiredForPublish = false): self
    {
        return new self('text', $translated, $nullable, $rules, requiredForPublish: $requiredForPublish);
    }

    public static function html(bool $translated = false, bool $nullable = false): self
    {
        return new self('html', $translated, $nullable);
    }

    /** @param list<string> $options */
    public static function enum(array $options, bool $nullable = false, bool $translated = false): self
    {
        return new self('enum', $translated, $nullable, options: $options);
    }

    public static function url(bool $translated = false, bool $nullable = true): self
    {
        return new self('url', $translated, $nullable);
    }

    public static function media(bool $translated = false, bool $nullable = true, string $mediaKind = 'image'): self
    {
        return new self('media', $translated, $nullable, mediaKind: $mediaKind);
    }

    public static function page(bool $translated = false, bool $nullable = true): self
    {
        return new self('page', $translated, $nullable);
    }

    public static function revision(bool $nullable = false, ?string $revisionKind = null): self
    {
        return new self('revision', nullable: $nullable, revisionKind: $revisionKind);
    }

    /** @param array<string, self> $properties */
    public static function object(array $properties, bool $translated = false): self
    {
        return new self('object', $translated, properties: $properties);
    }

    public static function list(self $items, bool $translated = false): self
    {
        return new self('list', $translated, items: $items);
    }
}
