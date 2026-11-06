<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use InvalidArgumentException;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;

/** A server-derived immutable source, never a browser-supplied admission proof. */
final readonly class SourceSnapshot
{
    private function __construct(public string $kind, public string $id, public string $version, public string $digest, public Document $document, public array $provenance) {}

    public static function fromDocument(string $kind, string $id, int|string $version, Document $document, array $provenance = []): self
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $kind) || $id === '' || strlen($id) > 191 || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D', (string) $version)) {
            throw new InvalidArgumentException('A trusted source requires an explicit bounded identity/version.');
        }

        return new self($kind, $id, (string) $version, (new CanonicalJson)->digest($document), $document, $provenance);
    }
}
