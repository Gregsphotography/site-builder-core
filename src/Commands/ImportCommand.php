<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\LegacyRows;
use SiteBuilder\Core\Exchange\Snapshot;
use Throwable;

final class ImportCommand extends Command
{
    protected $signature = 'site-builder:import {path} {--legacy : Convert the versioned plain-row legacy envelope} {--apply : Commit an eligible plan atomically; otherwise dry run} {--report= : Write the reconciliation report to a new private file}';

    protected $description = 'Plan or apply an identity-preserving content import; conflicts never overwrite target edits';

    public function handle(Exchange $exchange, LegacyRows $legacy): int
    {
        $path = $this->argument('path');
        $reportHandle = null;
        if ($this->option('report')) {
            $reportHandle = @fopen($this->option('report'), 'x');
            if ($reportHandle === false) {
                $this->components->error('Choose a new writable report path before importing.');

                return self::FAILURE;
            }
            chmod($this->option('report'), 0600);
        }
        try {
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 67108864) {
                throw new InvalidArgumentException('A readable snapshot of at most 64 MiB is required.');
            }
            $input = file_get_contents($path);
            $snapshot = $this->option('legacy') ? $legacy->convert($input) : Snapshot::decode($input);
            $report = $exchange->import($snapshot, DB::connection(), ! $this->option('apply'));
        } catch (Throwable $exception) {
            $report = ['format' => 'laravel-site-builder/import-report', 'version' => 1, 'status' => 'rejected', 'dry_run' => ! $this->option('apply'), 'transaction_committed' => false, 'cutover_eligible' => false,
                'diagnostics' => [['severity' => 'error', 'code' => 'unsupported_snapshot', 'path' => '/', 'message' => $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The snapshot could not be read or validated. Check its format, connection and registered host schema.']]];
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if ($this->option('report')) {
            fwrite($reportHandle, $json);
            fclose($reportHandle);
        }
        $this->line($json);

        return $report['cutover_eligible'] ? self::SUCCESS : self::FAILURE;
    }
}
