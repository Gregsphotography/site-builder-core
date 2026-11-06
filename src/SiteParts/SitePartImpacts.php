<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Models\Page;

final class SitePartImpacts
{
    public function __construct(private SitePartAccess $access, private ContentDependencies $dependencies, private Gate $gate, private CanonicalJson $json) {}

    public function report(Authenticatable $actor, PublishedPartReference $reference): array
    {
        $part = SitePart::query()->findOrFail($reference->resourceId);
        $this->access->authorize($actor, 'view', $part);
        $targets = [];
        $other = [];
        $protected = false;
        foreach ($this->dependencies->authoritativeUses($reference->resourceId, $reference->revisionId) as $use) {
            if ($use['kind'] === 'page') {
                $page = Page::query()->find($use['id']);
                if ($page === null || ! $this->gate->forUser($actor)->allows('view', $page)) {
                    $protected = true;

                    continue;
                }
                $entry = $targets[$page->id] ?? ['pageId' => $page->id, 'title' => $page->title, 'writeVersion' => $page->write_version, 'draftDigest' => $this->json->digest($page->draft_content), 'layout' => $page->layout, 'scopes' => [], 'canStage' => false, 'leaseHeld' => $page->editLock()->where('expires_at', '>', now())->exists()];
                $entry['scopes'] = array_values(array_unique([...$entry['scopes'], $use['scope']]));
                $entry['canStage'] = $entry['canStage'] || ($use['scope'] === 'draft' && $this->gate->forUser($actor)->allows('update', $page));
                $targets[$page->id] = $entry;
            } elseif (in_array($use['kind'], ['site-part', 'site-part-revision'], true)) {
                $source = $use['kind'] === 'site-part' ? SitePart::query()->find($use['id']) : SitePart::query()->find(SitePartRevision::query()->find($use['id'])?->resource_id);
                if ($source === null || ! $this->gate->forUser($actor)->allows('view', $source)) {
                    $protected = true;

                    continue;
                }
                $other[$use['kind'].':'.$use['id']] = ['kind' => $use['kind'], 'id' => $use['id'], 'name' => $source->name, 'scope' => $use['scope']];
            } else {
                // History/saved-version details require separate resource authorization;
                // disclose only their existence in this bounded page-staging report.
                $protected = true;
            }
        }

        return ['reference' => (array) $reference->jsonSerialize(), 'targets' => array_values($targets), 'otherAuthorizedUses' => array_values($other), 'protectedUses' => $protected, 'livePagesUnchanged' => true, 'maxTargets' => 20];
    }
}
