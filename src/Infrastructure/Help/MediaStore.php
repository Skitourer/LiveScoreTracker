<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Help;

use LiveScoreTracker\Infrastructure\Config\Branding;
use RuntimeException;

/**
 * Images used in the help, home and rules pages (spec § 12.3), kept in the
 * data folder (`media/`). PNG, JPEG or WebP only, read from the content.
 */
final class MediaStore
{
    public const MAX_BYTES = 2_097_152;

    public function __construct(private readonly string $dataDir) {}

    /**
     * @return list<array{name: string, size: int}>
     */
    public function all(): array
    {
        $files = [];
        foreach (glob($this->dataDir . '/media/*') ?: [] as $path) {
            if (self::validName(basename($path)) && is_file($path)) {
                $files[] = ['name' => basename($path), 'size' => (int) filesize($path)];
            }
        }

        return $files;
    }

    public function path(string $name): ?string
    {
        $path = $this->dataDir . '/media/' . $name;

        return self::validName($name) && is_file($path) ? $path : null;
    }

    /**
     * @throws RuntimeException with a translation key
     */
    public function save(string $originalName, string $content): string
    {
        if ($content === '' || \strlen($content) > self::MAX_BYTES) {
            throw new RuntimeException('config.contents.image_too_big');
        }
        $type = Branding::detect($content);
        if ($type === null) {
            throw new RuntimeException('config.contents.image_type');
        }
        $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', pathinfo($originalName, PATHINFO_FILENAME)));
        $base = trim(substr($base, 0, 40), '-');
        $name = ($base === '' ? 'image' : $base) . '.' . Branding::TYPES[$type];
        $dir = $this->dataDir . '/media';
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true)) {
            throw new RuntimeException('config.contents.image_write');
        }
        if (@file_put_contents($dir . '/' . $name, $content) === false) {
            throw new RuntimeException('config.contents.image_write');
        }

        return $name;
    }

    public function delete(string $name): void
    {
        $path = $this->path($name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private static function validName(string $name): bool
    {
        return preg_match('/^[a-z0-9-]+\.(png|jpg|webp)$/', $name) === 1;
    }
}
