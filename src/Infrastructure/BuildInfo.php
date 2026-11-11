<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure;

/**
 * Version of the running code, so the test site shows which commit is deployed.
 *
 * The deployment workflow writes a `REVISION` file (commit hash and date) at the
 * project root; without it, the code is assumed to be a local working copy.
 */
final class BuildInfo
{
    public const VERSION = '0.1.0-dev';

    public function __construct(
        public readonly string $version,
        public readonly string $revision,
        public readonly string $builtAt,
    ) {}

    public static function fromRoot(string $rootDir): self
    {
        $revision = 'local';
        $builtAt = '';
        $file = $rootDir . '/REVISION';

        if (is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $revision = substr(trim($lines[0] ?? 'unknown'), 0, 12);
            $builtAt = trim($lines[1] ?? '');
        }

        return new self(self::VERSION, $revision, $builtAt);
    }
}
