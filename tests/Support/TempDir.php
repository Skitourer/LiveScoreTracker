<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Portable removal of the temporary data directories created by tests
 * (no shell command, so it also works on Windows).
 */
final class TempDir
{
    /**
     * Removes a directory and its content; missing paths are ignored.
     * Best effort: a file still locked (e.g. an open SQLite handle on Windows) is left behind.
     */
    public static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    /**
     * Removes the test directories (`lst-*` in the system temp directory) left by earlier runs:
     * on Windows a SQLite file cannot be deleted while a test still holds its connection.
     * Only directories older than $minAge seconds are touched, so a concurrent run is not disturbed.
     */
    public static function purgeStale(int $minAge = 3600): void
    {
        $limit = time() - $minAge;
        foreach (glob(sys_get_temp_dir() . '/lst-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ((int) filemtime($dir) < $limit) {
                self::remove($dir);
            }
        }
    }
}
