<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Diagnostic;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\RenderFeature;
use SiteBuilder\Core\Design\DesignData;
use stdClass;

final class RenderFeatureRegistry
{
    private array $features = [];

    public function __construct(private CanonicalJson $json) {}

    public function register(RenderFeature $feature): self
    {
        $key = $feature->key().'@'.$feature->version();
        if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $feature->key()) || $feature->version() < 1 || isset($this->features[$key])) {
            throw new InvalidArgumentException('Render features need unique trusted key/version registrations.');
        }
        $this->features[$key] = $feature;

        return $this;
    }

    private function admitted(Document $document, ?Node $node): ?array
    {
        $envelope = ($node?->extensions ?? $document->extensions)->get('site-builder.render');
        if ($envelope === null) {
            return [];
        }
        if (! $envelope instanceof stdClass || ($envelope->version ?? null) !== 1 || count(get_object_vars($envelope)) < 2) {
            return null;
        }
        $admitted = [];
        foreach ($envelope as $key => $payload) {
            if ($key === 'version') {
                continue;
            }
            if (! $payload instanceof stdClass || ! is_int($payload->version ?? null) || ($feature = $this->features[$key.'@'.$payload->version] ?? null) === null || ! $feature->readable($payload, $node !== null)) {
                return null;
            }
            $admitted[] = [$feature, $payload];
        }

        return $admitted;
    }

    public function readOnly(Document $document, ?Node $node = null): bool
    {
        return $this->admitted($document, $node) === null;
    }

    public function diagnostics(Document $document, ?Node $node, RenderContext $context): array
    {
        $admitted = $this->admitted($document, $node);
        if ($admitted === null) {
            return [new Diagnostic('unsupported_extension', '/extensions/site-builder.render', 'A required render envelope/feature version is unavailable or malformed; preserve its exact data.')];
        }
        $diagnostics = [];
        foreach ($admitted as [$feature, $payload]) {
            $diagnostics = [...$diagnostics, ...$feature->diagnostics($payload, $document, $node, $context)];
        }

        return $diagnostics;
    }

    public function renderable(Document $document, ?Node $node, RenderContext $context): bool
    {
        return $this->diagnostics($document, $node, $context) === [];
    }

    public function assertPreserved(?Document $old, Document $new): void
    {
        if ($old !== null && $this->readOnly($old) && ! $this->json->equals($old, $new)) {
            throw ValidationException::withMessages(['document' => 'Unavailable document render features make this content scope read-only.']);
        }
        $before = $old === null ? [] : DesignData::nodes($old);
        foreach (DesignData::nodes($new) as $id => $node) {
            if ($this->readOnly($new, $node) || (isset($before[$id]) && $this->readOnly($old, $before[$id]))) {
                if (! isset($before[$id]) || ! $this->json->equals($before[$id], $node)) {
                    throw ValidationException::withMessages(['document' => 'Unavailable node render features are read-only; explicit removal is permitted.']);
                }
            }
        }
        if ($this->readOnly($new) && ($old === null || ! $this->json->equals($old, $new))) {
            throw ValidationException::withMessages(['document' => 'Unknown document render capabilities cannot be introduced by normal saves.']);
        }
    }

    /** Registered root features may be retained only as inactive section provenance. */
    public function projectSections(Document $document): ?stdClass
    {
        $envelope = $document->extensions->get('site-builder.render');
        if ($envelope === null) {
            return null;
        }
        $admitted = $this->admitted($document, null);
        if ($admitted === null) {
            throw ValidationException::withMessages(['source' => 'An unavailable required document render feature cannot be projected into sections.']);
        }
        foreach ($admitted as [$feature, $payload]) {
            if (! $feature->projectSections($payload)) {
                throw ValidationException::withMessages(['source' => 'This required render feature has no scoped-copy adapter.']);
            }
        }

        return $envelope;
    }
}
