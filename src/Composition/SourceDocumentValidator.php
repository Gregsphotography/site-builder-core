<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Contracts\HasPlacementPolicy;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use SiteBuilder\Core\Rendering\RenderContext;

/** A detached trusted source has no destination root yet; persistence always validates the assembled owner. */
final class SourceDocumentValidator
{
    public function __construct(private DocumentValidator $validator, private ComponentRegistry $components) {}

    public function validate(Document $document, ?RenderContext $context = null): array
    {
        try {
            return $this->validator->validate($document, context: $context);
        } catch (InvalidDocument $exception) {
            $errors = array_values(array_filter($exception->diagnostics, function ($diagnostic) use ($document): bool {
                if ($diagnostic->code !== 'disallowed_placement' || ! preg_match('~^/nodes/(0|[1-9][0-9]*)$~D', $diagnostic->path, $match)) {
                    return true;
                }
                $node = $document->nodes[(int) $match[1]] ?? null;
                $definition = $node === null ? null : $this->components->resolve($node->type);

                return ! ($definition instanceof HasPlacementPolicy && $definition->version() === $node->version && ! $definition->placement()->rootAllowed && $definition->placement()->childAllowed);
            }));
            if ($errors !== []) {
                throw new InvalidDocument($errors);
            }

            return [];
        }
    }
}
