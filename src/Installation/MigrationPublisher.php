<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use DateTimeImmutable;
use RuntimeException;

final class MigrationPublisher
{
    /** Publish only migration names not already present under any timestamp. @return list<string> */
    public function publish(string $source, string $destination, ?DateTimeImmutable $timestamp = null): array
    {
        $timestamp ??= new DateTimeImmutable;
        $sources = glob($source.'/*.php') ?: [];
        sort($sources, SORT_STRING);
        if ($sources === []) {
            throw new RuntimeException('The package migration resources are missing.');
        }
        if (! is_dir($destination) && ! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            throw new RuntimeException('Could not create the application migration directory.');
        }
        $existing = [];
        foreach (glob($destination.'/*.php') ?: [] as $file) {
            $existing[$this->name($file)][] = $file;
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($file), $match)) {
                $publishedAt = DateTimeImmutable::createFromFormat('!Y_m_d_His', $match[1], $timestamp->getTimezone());
                if ($publishedAt !== false && $publishedAt >= $timestamp) {
                    $timestamp = $publishedAt->modify('+1 second');
                }
            }
        }
        $published = [];
        foreach ($sources as $index => $file) {
            $name = $this->name($file);
            if (count($existing[$name] ?? []) > 1) {
                throw new RuntimeException('Resolve duplicate published migration names before installing: '.$name);
            }
            if (isset($existing[$name])) {
                continue;
            }
            $target = $destination.'/'.$timestamp->modify('+'.$index.' seconds')->format('Y_m_d_His').'_'.$name;
            $contents = file_get_contents($file);
            $handle = @fopen($target, 'x');
            if ($contents === false || $handle === false) {
                if (is_resource($handle)) {
                    fclose($handle);
                    unlink($target);
                }
                throw new RuntimeException('Could not publish migration without overwriting a file: '.$name);
            }
            try {
                if (fwrite($handle, $contents) !== strlen($contents)) {
                    unlink($target);
                    throw new RuntimeException('Could not finish publishing migration: '.$name);
                }
            } finally {
                fclose($handle);
            }
            $published[] = $target;
        }

        return $published;
    }

    private function name(string $path): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path));
    }

    public function plan(array $sources, SetupFilesystem $files): array
    {
        $existing = [];
        $latest = new DateTimeImmutable('2000-01-01 00:00:00 UTC');
        foreach (glob($files->root.'/database/migrations/*.php') ?: [] as $file) {
            $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
            if (isset($existing[$name])) {
                throw new SetupFailure('migration-conflict', 'Duplicate published migration identities require manual review.', 3);
            }
            $existing[$name] = 'database/migrations/'.basename($file);
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($file), $match)) {
                $time = DateTimeImmutable::createFromFormat('!Y_m_d_His', $match[1]);
                if ($time !== false && $time >= $latest) {
                    $latest = $time->modify('+1 second');
                }
            }
        }
        $result = [];
        $seen = [];
        foreach (array_unique($sources) as $source) {
            $paths = glob($source.'/*.php') ?: [];
            if ($paths === []) {
                throw new SetupFailure('migrations-unavailable', 'Installed migration resources are unavailable.', 2);
            }
            sort($paths);
            foreach ($paths as $file) {
                $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
                if (isset($seen[$name])) {
                    throw new SetupFailure('migration-conflict', 'Contributors declared duplicate migration identities.', 3);
                }
                $seen[$name] = true;
                $path = $existing[$name] ?? 'database/migrations/'.$latest->format('Y_m_d_His').'_'.$name;
                if (! isset($existing[$name])) {
                    $latest = $latest->modify('+1 second');
                }
                $result[] = new SetupFile($path, file_get_contents($file), 'Publish the installed package migration identity without rewriting history.');
            }
        }

        return $result;
    }
}
