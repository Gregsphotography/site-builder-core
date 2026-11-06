<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\LegacyRows;

final class ExportCommand extends Command
{
    protected $signature = 'site-builder:export {path} {--source= : Stable source namespace} {--legacy : SELECT-only capture of legacy tables and explicitly registered host facts}';

    protected $description = 'Export registered builder and host content; excludes accounts, sessions, leases and business records';

    public function handle(Exchange $exchange, LegacyRows $legacy): int
    {
        $path = $this->argument('path');
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', (string) $this->option('source')) || file_exists($path)) {
            $this->components->error('Supply a stable --source namespace and a new private file path.');

            return self::FAILURE;
        }
        $content = $this->option('legacy') ? $legacy->capture(DB::connection(), (string) $this->option('source')) : $exchange->export(DB::connection(), (string) $this->option('source'))->encode();
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            $this->components->error('Choose a new export path; existing files are never overwritten.');

            return self::FAILURE;
        }
        chmod($path, 0600);
        fwrite($handle, $content);
        fclose($handle);
        $this->components->info('Content exported. Keep this private content snapshot outside the public directory.');

        return self::SUCCESS;
    }
}
