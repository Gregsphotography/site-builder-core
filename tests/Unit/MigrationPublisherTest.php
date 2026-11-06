<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SiteBuilder\Core\Installation\MigrationPublisher;

class MigrationPublisherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/site-builder-migrations-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/source', 0755, true);
        mkdir($this->directory.'/app', 0755, true);
        file_put_contents($this->directory.'/source/0001_01_01_000100_create_example.php', '<?php return ["initial" => true];');
    }

    protected function tearDown(): void
    {
        foreach (['source', 'app'] as $folder) {
            foreach (glob($this->directory.'/'.$folder.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->directory.'/'.$folder);
        }
        rmdir($this->directory);
    }

    public function test_reinstall_on_a_later_date_preserves_edited_migration_history(): void
    {
        $publisher = new MigrationPublisher;
        $first = $publisher->publish($this->directory.'/source', $this->directory.'/app', new DateTimeImmutable('2026-11-05T10:00:00Z'));
        file_put_contents($first[0], '<?php return ["host_edit" => true];');
        $second = $publisher->publish($this->directory.'/source', $this->directory.'/app', new DateTimeImmutable('2026-11-06T15:00:00Z'));
        $this->assertSame([], $second);
        $this->assertCount(1, glob($this->directory.'/app/*.php'));
        $this->assertSame('<?php return ["host_edit" => true];', file_get_contents($first[0]));
    }

    public function test_package_upgrade_adds_only_new_migrations_in_source_order(): void
    {
        $publisher = new MigrationPublisher;
        $first = $publisher->publish($this->directory.'/source', $this->directory.'/app');
        file_put_contents($this->directory.'/source/0001_01_01_000200_add_example_field.php', '<?php return ["new" => true];');
        $second = $publisher->publish($this->directory.'/source', $this->directory.'/app', new DateTimeImmutable('2026-11-06T15:00:00Z'));
        $this->assertCount(1, $second);
        $this->assertStringEndsWith('_add_example_field.php', $second[0]);
        $this->assertSame('<?php return ["initial" => true];', file_get_contents($first[0]));
        $this->assertCount(2, glob($this->directory.'/app/*.php'));
    }

    public function test_ambiguous_existing_history_fails_without_deleting_either_file(): void
    {
        file_put_contents($this->directory.'/app/2026_11_05_100000_create_example.php', '<?php return 1;');
        file_put_contents($this->directory.'/app/2026_11_06_100000_create_example.php', '<?php return 2;');
        try {
            (new MigrationPublisher)->publish($this->directory.'/source', $this->directory.'/app');
            $this->fail('Duplicate historical names must be reported.');
        } catch (RuntimeException) {
            $this->assertCount(2, glob($this->directory.'/app/*.php'));
        }
    }

    public function test_sequential_installers_in_the_same_second_append_after_all_published_dependencies(): void
    {
        $publisher = new MigrationPublisher;
        $time = new DateTimeImmutable('2026-11-06T10:00:00Z');
        file_put_contents($this->directory.'/source/0001_01_01_000200_add_example_field.php', '<?php return ["upgrade" => true];');
        $core = $publisher->publish($this->directory.'/source', $this->directory.'/app', $time);
        unlink($this->directory.'/source/0001_01_01_000100_create_example.php');
        unlink($this->directory.'/source/0001_01_01_000200_add_example_field.php');
        file_put_contents($this->directory.'/source/0001_01_01_000100_aaa_optional_with_fk.php', '<?php return ["optional" => true];');
        $optional = $publisher->publish($this->directory.'/source', $this->directory.'/app', $time);
        $this->assertGreaterThan(basename($core[1]), basename($optional[0]));
        $this->assertSame('2026_11_06_100002_aaa_optional_with_fk.php', basename($optional[0]));
        $this->assertSame('<?php return ["initial" => true];', file_get_contents($core[0]));
        $this->assertSame([], $publisher->publish($this->directory.'/source', $this->directory.'/app', $time));
    }
}
