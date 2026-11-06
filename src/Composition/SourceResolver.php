<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;

final class SourceResolver
{
    public function __construct(private StartingPointRegistry $registry, private SavedSectionCommands $saved, private LocaleCatalog $locales) {}

    public function resolve(Authenticatable $actor, SourceSelection $selection, bool $lock = false): SourceSnapshot
    {
        if (! $this->locales->has($selection->locale)) {
            throw new InvalidArgumentException('The source locale is not configured.');
        }
        if ($selection->kind === 'saved-section') {
            if ($selection->variant !== null) {
                throw new InvalidArgumentException('Saved sections do not select a code variant.');
            }
            $source = $this->saved->snapshot($actor, $selection->id, $selection->version, $lock);
        } else {
            $source = $this->registry->snapshot($selection->id, $selection->version, $selection->locale, $selection->variant);
        }
        if ($source->kind !== $selection->kind || ! hash_equals($source->digest, $selection->digest)) {
            throw new WriteConflict('source_version_or_digest_changed');
        }
        SectionSourceScope::project($source->document);

        return $source;
    }
}
