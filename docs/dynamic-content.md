# Dynamic content and interactive components

This release adds seven standard widgets: `standard.content-listing`, `standard.search-box`, `standard.search-results`, `standard.image-comparison`, `standard.image-hotspots`, `standard.carousel` and `standard.timeline`. Popups and drawers are reusable `overlay` site parts, edited in the separate popup library. Ecommerce and automatic popup campaigns are not included.

## Install and configure

Require matching Core, Components and Filament releases. Publish the current Core and component assets and refresh Filament assets. Existing configuration is preserved.

```sh
php artisan site-builder:install --no-interaction
php artisan site-builder:components-install --no-interaction
php artisan filament:assets --no-interaction
php artisan site-builder:discovery-install --no-interaction
php artisan migrate --force --no-interaction
php artisan site-builder:discovery-rebuild --no-interaction
php artisan site-builder:discovery-doctor --no-interaction
```

The index is optional, derived data. Installing it does not create content or enable extra public routes. Set `site-builder.discovery.enabled` to `true` to enable the bounded, throttled suggestions endpoint under the configured public route prefix. Existing explicit host routes retain priority. Create and publish a page containing Search results, then select that page in each Search box. An optional `site-builder.discovery.results_page` supplies the default page ID. Suggestions inherit the selected results component's source/type/topic restrictions; ordinary GET submission works without JavaScript.

Page settings include translated discovery summaries, thumbnails, topics, editorial dates and listing/search inclusion switches. These fields remain private until the document is published and participate in content history. Existing title, type, URL and SEO metadata retain their existing immediate behavior. Noindex pages, drafts and pages without a URL in the current locale are excluded. Thumbnail references participate in whole-document copy and native exchange mapping; section captures omit page discovery metadata.

Use the existing optional site-parts installer and migrations before creating overlays. Existing host policies need explicit abilities for the new `overlay` kind. The demo grants these only to its existing builder administrators.

## Content adapters

Core owns `SiteBuilder\Core\Discovery\ContentSourceProvider`, `ContentSources`, `ContentContext`, `ContentItem`, `ContentQuery`, `ContentResults` and `ContentIndex`. The initial source is `builder.pages`. Register additional providers in trusted host PHP:

```php
$this->app->afterResolving(ContentSources::class, function (ContentSources $sources): void {
    $sources->register('host.projects', app(PublishedProjects::class));
});
```

A provider implements `label(): string`, `items(ContentContext): iterable` and `hydrate(array $ids, ContentContext): array`. Enumeration and batch hydration must apply the same current publication, locale and server-side access scope. Hydration returns a map keyed by item ID and omits withdrawn or private records. Never return raw host models. A `ContentItem` contains an opaque ID, locale, title, safe public URL, excerpt, plain public body, optional resolved public image URL and alternative text, optional `YYYY-MM-DD` date, type, topics, optional subtitle and inclusion flags. Fields are bounded; dates and URLs are validated.

Hosts can use `ContentSources::scopeUsing(Closure)` for a current server-derived scope identifier. Never derive this scope from a visitor's query parameters. Rebuild and doctor commands operate in the current host scope; applications with multiple scopes must run them within each trusted scope.

Notify the index after committing changes to a host source, including visibility changes:

```php
DB::afterCommit(fn () => app(ContentIndex::class)->refresh('host.projects', [$projectId], $locale));
```

Builder page publications, metadata/URL changes, removals and relevant immutable-part changes are synchronized automatically. Rebuild after bulk SQL imports, backup restores or adapter changes. All index writers share a database lock. Revalidation suppresses stale/private hits; source failures yield an unavailable state. An index update failure does not misreport an already-committed content write as failed: it is logged privately and affected derived rows are discarded when possible. Doctor compares current source projections with stored fingerprints and reports missing/stale rows without changing content.

Custom builder components also opt into public text extraction explicitly. Register a callback in `ContentTextRegistry` receiving `(Node $node, array $resolvedProps, RenderContext $context): string`. Return only text that is public in that rendering. Arbitrary text properties, document JSON, host configuration, forms, overlays, headers/footers and dynamically generated collections are never indexed by default. The standard pack registers its own public text extractors; manually authored timeline/carousel content is included.

Queries support keyword, source, type, topic, stable sorting and pagination. All keyword words must match; case and diacritics are normalized, with title matches weighted above summaries/topics and body text. Listings default to 12 results and cap at 48. Undated entries sort after dated entries. Each listing/timeline occurrence has separate query/pagination state. Search results use ordinary `q`, `source`, `type`, `tag` and `page` parameters. Core's public controller admits bounded `PublicQuery` state to `RenderContext`; previews ignore it.

Components' `ContentPresentations::register($preset, Closure)` lets trusted host code override a card preset with an `HtmlString` renderer receiving the public item and display options. Documents never contain PHP, SQL, model names or template paths. Presets cover cards, image grids and compact lists; there is no visual loop-template editor.

## Interactions and reuse

Buttons, CTA and navigation accept a typed `overlay` revision reference. The overlay takes priority while the stored page/URL remains available when the overlay is cleared. `PropertyDefinition::revision(nullable: true, revisionKind: 'overlay')` declares this kind for host components. The standard composition capability is required, just as for other immutable references; SDK callers can use `CompositionData::promote()`.

Dialog and left/right drawer settings, sizes and translated titles are stored with the immutable overlay document. Multiple triggers share one panel and form state per page/revision/locale. Publishing a new revision does not silently change pages: review and stage pin updates in selected drafts, then publish those pages. Existing use, archive, withdrawal, cycle/depth limits and native graph mapping apply. Native dialogs handle focus and Escape, including a gallery lightbox inside a popup. Without enhancement, overlay content remains readable at its anchor. Popup content is excluded from the page's search body and table of contents. Existing host layout slots do not change.

Comparison widgets retain both labelled images without JavaScript. Hotspots use exact integer coordinates from 0 to 10,000; the editor presents percentages plus visual placement and keyboard movement. Numbered details remain readable alongside enhanced markers. Both widgets use typed media references and translated descriptions.

One carousel engine serves image, logo, card, testimonial and team presets. Carousels and timelines support authored items or registered sources. Carousel autoplay is off by default; opt-in playback has a pause control and pauses on focus, hover, interaction, hidden documents and off-screen placement. Reduced motion disables automatic playback. There are no cloned slides. Timelines preserve authored order or use chronological source ordering and label undated entries. Both remain ordinary readable lists without JavaScript.

Public query-dependent responses use private/no-store caching. `PageResponse` handles this automatically. Fragment hosts must wrap rendering and asset emission in `PublicAssets::scope()` and honor `PublicAssets::privateResponse()`. Private previews retain inert submissions/navigation and do not start public interaction scripts.

## Database and upgrade boundaries

The new index and widgets are exercised on SQLite, MySQL 8.4 and PostgreSQL 18. PostgreSQL authoring fixes used by the demo are included in Core, replacing its old version-pinned vendor patch. Clipboard namespace identity on PostgreSQL uses `pg_control_system()` and requires permission to read the stable cluster identifier. Full native exchange continues to support SQLite/MySQL; the targeted PostgreSQL checks do not claim PostgreSQL exchange support. Derived discovery rows are excluded from content exports and are rebuilt after restores/imports.

Keep coherent code/database/media backups and rehearse upgrades against the actual host configuration. Preserve host credentials, accounts, policies and authored content. Newly added component documents require the matching packages to render; a rollback of such a deployment must restore the corresponding content backup as well as the previous code.
