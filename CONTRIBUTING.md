# Development

Use PHP 8.5+, Composer 2 and Node 24. Install the matching tagged core and optional components dependencies; before indexing, point a disposable local root manifest at the reviewed local Git repositories. Do not commit that staging repository configuration.

```sh
composer install
npm ci --ignore-scripts
npm run build:check
npm test
composer test
```

MySQL jobs require MySQL 8.4 and an isolated `site_builder_*_test` schema; never point tests at a host/production database. Race barriers and subprocesses have deadlines. SQLite skips MySQL-only race checks. Filament builds resolve the core protocol through the installed Composer package, never a sibling checkout. Consumer installations require neither Node nor development dependencies.
