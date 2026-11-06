<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

/** All destinations are app-relative; no traversal or symlink component is accepted. */
class SetupFilesystem
{
    public readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = realpath($root ?? base_path()) ?: throw new SetupFailure('app-unavailable', 'The application root is unavailable.', 2);
    }

    public function path(string $path, bool $allowFinalLink = false): string
    {
        if (strlen($path) > 255 || ! preg_match('~^(?:app|bootstrap|config|database|public|resources|routes|storage)/[A-Za-z0-9_./-]+$~D', $path) || array_any(explode('/', $path), fn ($part) => $part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.'))) {
            throw new SetupFailure('unsafe-path', 'Setup destinations must be normalized application-relative paths.', 3);
        }
        $current = $this->root;
        $parts = explode('/', $path);
        foreach ($parts as $index => $part) {
            $current .= '/'.$part;
            if (is_link($current) && ! ($allowFinalLink && $index === count($parts) - 1)) {
                throw new SetupFailure('symlink-conflict', 'A setup destination contains a symbolic link.', 3);
            }
            if ($index < count($parts) - 1 && file_exists($current) && ! is_dir($current)) {
                throw new SetupFailure('path-conflict', 'A setup parent is not a directory.', 3);
            }
        }

        return $current;
    }

    public function hash(string $path): ?string
    {
        $file = $this->path($path);
        if (file_exists($file) && ! is_file($file)) {
            throw new SetupFailure('path-conflict', 'A setup destination is not a regular file.', 3);
        }

        return is_file($file) ? hash_file('sha256', $file) : null;
    }

    public function read(string $path): ?string
    {
        return $this->hash($path) === null ? null : file_get_contents($this->path($path));
    }

    public function write(string $path, string $bytes, ?string $expected, int $mode = 0644): void
    {
        if ($this->hash($path) !== $expected) {
            throw new SetupFailure('file-changed', 'A reviewed file changed before replacement.', 3);
        }
        $file = $this->path($path);
        $directory = dirname($file);
        if (! is_dir($directory) && ! mkdir($directory, str_starts_with($path, 'storage/') ? 0700 : 0755, true) && ! is_dir($directory)) {
            throw new SetupFailure('directory-write-failed', 'A setup directory could not be created.', 70);
        }
        $this->path($path);
        if (str_starts_with($path, 'storage/app/site-builder/setup/')) {
            $private = $this->root.'/storage/app/site-builder/setup';
            foreach (explode('/', substr($path, strlen('storage/app/site-builder/setup/'))) as $part) {
                if (is_dir($private) && ! chmod($private, 0700)) {
                    throw new SetupFailure('private-directory-required', 'Setup state requires private operator directories.', 70);
                }
                $private .= '/'.$part;
            }
        }
        $temporary = tempnam($directory, 'sb-setup-');
        if ($temporary === false) {
            throw new SetupFailure('file-write-failed', 'A setup temporary file could not be created.', 70);
        }
        try {
            chmod($temporary, $mode);
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || $this->hash($path) !== $expected || ! rename($temporary, $file)) {
                throw new SetupFailure('file-write-failed', 'A setup file could not be safely replaced.', 70);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function state(string $path, array $data): void
    {
        $this->write($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", $this->hash($path), 0600);
    }

    public function owned(): array
    {
        $raw = $this->read('storage/app/site-builder/setup/owned-v1.json');
        if ($raw === null) {
            return [];
        }
        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SetupFailure('invalid-ownership', 'The setup ownership manifest is invalid.', 3);
        }
        if (($value['format'] ?? null) !== 'laravel-site-builder/setup-owned' || ($value['version'] ?? null) !== 1 || ! is_array($value['files'] ?? null) || count($value['files']) > 5000) {
            throw new SetupFailure('invalid-ownership', 'The setup ownership manifest is unsupported.', 3);
        }
        foreach ($value['files'] as $path => $hash) {
            $this->path($path);
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new SetupFailure('invalid-ownership', 'Invalid setup ownership entry.', 3);
            }
        }

        return $value['files'];
    }

    public function lock(): mixed
    {
        $path = 'storage/app/site-builder/setup/apply-lock';
        if ($this->hash($path) === null) {
            $this->write($path, '', null, 0600);
        }
        $handle = fopen($this->path($path), 'r+');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new SetupFailure('setup-busy', 'Another setup operation is running.', 3);
        }

        return $handle;
    }
}
