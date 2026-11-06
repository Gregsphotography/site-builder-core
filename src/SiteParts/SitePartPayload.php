<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use InvalidArgumentException;
use JsonSerializable;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\ThemeIdentity;
use stdClass;

final readonly class SitePartPayload implements JsonSerializable
{
    public const FORMAT = 'laravel-site-builder/site-part';

    private function __construct(public string $kind, public ?Document $document, public ?JsonObject $design) {}

    public static function document(string $kind, Document $document): self
    {
        if (! in_array($kind, ['section', 'header', 'footer'], true)) {
            throw new InvalidArgumentException('Document site parts must be section, header or footer.');
        }
        app(DocumentCodec::class)->encode($document);

        return new self($kind, $document, null);
    }

    public static function design(ThemeIdentity $theme, array $base = [], array $responsive = []): self
    {
        $design = (object) ['version' => 1, 'theme' => (object) ['id' => $theme->id, 'version' => $theme->version, 'preset' => $theme->preset], 'base' => (object) $base, 'responsive' => (object) array_map(fn ($map) => (object) $map, $responsive)];
        if (! DesignData::shape($design, false)) {
            throw new InvalidArgumentException('Site-design payloads require the supported pinned design shape.');
        }

        return new self('design', null, new JsonObject($design));
    }

    public static function decode(string $json): self
    {
        if (strlen($json) > 3 * 1024 * 1024) {
            throw new InvalidArgumentException('Site-part payload exceeds the bounded size limit.');
        }
        $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (! $data instanceof stdClass || ($data->format ?? null) !== self::FORMAT || ($data->version ?? null) !== 1 || ! is_string($data->kind ?? null) || array_diff(array_keys(get_object_vars($data)), ['format', 'version', 'kind', 'document', 'design']) !== []) {
            throw new InvalidArgumentException('Unsupported site-part payload format/version.');
        }
        if ($data->kind === 'design') {
            if (property_exists($data, 'document') || ! ($data->design ?? null) instanceof stdClass || ! DesignData::shape($data->design, false) || ! isset($data->design->theme)) {
                throw new InvalidArgumentException('A site-design payload contains only pinned design values.');
            }

            return new self('design', null, new JsonObject($data->design));
        }
        if (property_exists($data, 'design') || ! ($data->document ?? null) instanceof stdClass) {
            throw new InvalidArgumentException('A document site part cannot contain a separate site design.');
        }

        return self::document($data->kind, app(DocumentCodec::class)->decode(json_encode($data->document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
    }

    public function validationDocument(): Document
    {
        return $this->document ?? new Document(extensions: new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => $this->design->toObject()]]));
    }

    public function jsonSerialize(): stdClass
    {
        return (object) ['format' => self::FORMAT, 'version' => 1, 'kind' => $this->kind, ...($this->document === null ? ['design' => $this->design->toObject()] : ['document' => $this->document])];
    }
}
