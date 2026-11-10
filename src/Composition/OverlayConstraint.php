<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Content\Diagnostic;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Contracts\DocumentConstraint;
use SiteBuilder\Core\Rendering\RenderContext;

final class OverlayConstraint implements DocumentConstraint
{
    public function diagnostics(Document $document, RenderContext $context, bool $publishing): array
    {
        $data = $document->extensions->get(OverlaySettings::KEY);
        if ($data === null) {
            return [];
        }
        $valid = $data instanceof \stdClass && ($data->version ?? null) === 1 && in_array($data->presentation ?? null, ['dialog', 'drawer-left', 'drawer-right'], true) && in_array($data->size ?? null, ['small', 'medium', 'large', 'full'], true) && ($data->labels ?? null) instanceof \stdClass && array_diff(array_keys(get_object_vars($data)), ['version', 'presentation', 'size', 'labels']) === [];
        if ($valid) {
            foreach ($data->labels as $label) {
                $valid = $valid && is_string($label) && trim($label) !== '' && mb_strlen($label) <= 255;
            }
        }

        return $valid ? [] : [new Diagnostic('invalid_overlay_settings', '/extensions/'.OverlaySettings::KEY, 'Choose a supported overlay presentation, size and translated accessible title.')];
    }
}
