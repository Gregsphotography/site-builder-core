# Site Builder Core

MIT-licensed Laravel Site Builder package. PHP ^8.5 / Laravel ^13.40; the optional editor uses Filament ^5.11 / Livewire ^4.4.8. Later Laravel majors are not yet supported.

This 0.5.0-rc.4 source is prepared for release review. Public availability is not implied by this file. After the aligned packages are indexed:

```sh
composer require gregsphotography/site-builder-core:0.5.0-rc.4
```

Install matching core/components explicitly for the standard public profile; core/filament supports a custom editor without components.

See [installation](docs/installation.md), [upgrade](docs/upgrade.md), [development](CONTRIBUTING.md), [support](docs/support.md) and [notices](THIRD_PARTY_NOTICES.md).

Content identifiers, SiteBuilder PHP namespaces, database tables and existing publications remain stable across the vendor-name change. Provider boot does not install schema, create accounts or publish content.

## Upgrading to 0.5.0-rc.4

Publish the new core migrations with `php artisan site-builder:install`. Sites using albums also run `php artisan site-builder:components-install --media`. Run `php artisan migrate`, refresh component assets with `php artisan site-builder:components-install` and editor assets with `php artisan filament:assets` for the packages installed. For scheduled publication, run the Laravel scheduler every minute and verify `site-builder:publish-due` appears in `php artisan schedule:list`.

See [practical blocks, filtering and media](docs/practical-blocks.md) and [optional enquiry forms](docs/forms.md) for the composition release. See [dynamic content, search and interactive components](docs/dynamic-content.md) for this release.
