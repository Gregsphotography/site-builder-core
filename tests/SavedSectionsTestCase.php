<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests;

abstract class SavedSectionsTestCase extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', $this->savedMigrationOptions())->assertSuccessful();
    }

    protected function savedMigrationOptions(): array
    {
        return ['--path' => realpath(__DIR__.'/../database/saved-sections-migrations'), '--realpath' => true, '--force' => true];
    }
}
