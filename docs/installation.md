# Installation

After public indexing, explicitly require every needed prerelease package at 0.3.0-rc.10:

```sh
composer require gregsphotography/site-builder-core:0.3.0-rc.10 gregsphotography/site-builder-components:0.3.0-rc.10
# Add gregsphotography/site-builder-filament:0.3.0-rc.10 for an already installed authenticated Filament panel.
cp vendor/gregsphotography/site-builder-core/resources/setup/public-v1.json builder-setup.json
# Review the complete choices, then:
php artisan site-builder:setup --answers=builder-setup.json --plan --json --no-interaction
php artisan site-builder:setup --answers=builder-setup.json --apply --no-interaction
php artisan site-builder:setup --answers=builder-setup.json --apply --run-migrations --no-interaction
php artisan site-builder:doctor --json
```

For interactive review run `site-builder:setup`. Use editor-v1.json for an existing panel/guard, custom-v1.json for core-only/custom definitions. Default route subtree is builder. Existing config, providers and panels require explicit manual integration; register the generated provider and merge reviewed config, then explicitly rebuild config/routes/views caches. Never infer account privileges: select existing policies or exact existing actor grants; publish, site-part use/withdraw and media remain separate. Samples are opt-in drafts. No Composer/npm/panel install/account creation is run by setup. Require doctor ready, then test create/edit/preview/publish and the actual public URL.

Schemas are in the installed core resources/schema directory. Exit codes: 0 ready/valid plan, 1 needs action, 2 unavailable, 3 stale/conflict, 64 invalid, 70 partial. A complete receipt may still need manual integration. Retain the private receipt and resume with original answers and execution flags; changed completed files/schema/inputs block continuation. No destructive rollback is attempted.
