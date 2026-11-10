# Site Builder Core

MIT-licensed Laravel Site Builder package. PHP ^8.5 / Laravel ^13.40; the optional editor uses Filament ^5.11 / Livewire ^4.4.8. Later Laravel majors are not yet supported.

This 0.5.0-rc.1 source is prepared for release review. Public availability is not implied by this file. After the aligned packages are indexed:

```sh
composer require gregsphotography/site-builder-core:0.5.0-rc.1
```

Install matching core/components explicitly for the standard public profile; core/filament supports a custom editor without components.

See [installation](docs/installation.md), [upgrade](docs/upgrade.md), [development](CONTRIBUTING.md), [support](docs/support.md) and [notices](THIRD_PARTY_NOTICES.md).

Content identifiers, SiteBuilder PHP namespaces, database tables and existing publications remain stable across the vendor-name change. Provider boot does not install schema, create accounts or publish content.

See [practical blocks, filtering and media](docs/practical-blocks.md) and [optional enquiry forms](docs/forms.md) for the composition release. See [dynamic content, search and interactive components](docs/dynamic-content.md) for this release.
