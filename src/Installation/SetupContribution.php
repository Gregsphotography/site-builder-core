<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

final readonly class SetupContribution
{
    /** @param list<SetupFile> $files @param list<string> $migrationSources @param list<DoctorCheck> $checks */
    public function __construct(public array $files = [], public array $migrationSources = [], public array $checks = []) {}

    public static function assets(string $source, string $destination): array
    {
        if (! is_dir($source)) {
            throw new SetupFailure('assets-unavailable', 'Installed compiled asset resources are unavailable.', 2);
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new SetupFailure('invalid-asset', 'An installed asset is not a regular file.', 2);
            }
            $relative = substr($file->getPathname(), strlen($source) + 1);
            $files[] = new SetupFile($destination.'/'.$relative, file_get_contents($file->getPathname()), 'Publish installed compiled asset.');
        }
        usort($files, fn ($a, $b) => strcmp($a->path, $b->path));

        return $files;
    }
}
