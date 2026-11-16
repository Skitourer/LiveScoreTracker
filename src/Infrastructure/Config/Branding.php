<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Config;

use LiveScoreTracker\Domain\Config\Theme;
use RuntimeException;

/**
 * Logo file and accent stylesheet (spec § 12.3). The logo lives in the data
 * folder (`branding/logo.<ext>`); SVG is refused (it can carry scripts).
 */
final class Branding
{
    public const MAX_LOGO_BYTES = 1_048_576;

    /** Accepted image types => extension. */
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(private readonly string $dataDir) {}

    public function logoPath(Theme $theme): ?string
    {
        if ($theme->logo === null) {
            return null;
        }
        $path = $this->dataDir . '/branding/' . $theme->logo;

        return is_file($path) ? $path : null;
    }

    /**
     * The logo as a data: URL, for the PDF documents.
     */
    public function logoDataUri(Theme $theme): ?string
    {
        $path = $this->logoPath($theme);
        $content = $path === null ? false : file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return 'data:' . self::mimeType($path) . ';base64,' . base64_encode($content);
    }

    /**
     * Checks an uploaded image and stores it; returns its file name for the configuration.
     *
     * @throws RuntimeException with a translation key
     */
    public function saveLogo(string $content): string
    {
        if ($content === '' || \strlen($content) > self::MAX_LOGO_BYTES) {
            throw new RuntimeException('config.theme.logo_too_big');
        }
        $type = self::detect($content);
        if ($type === null) {
            throw new RuntimeException('config.theme.logo_type');
        }
        $dir = $this->dataDir . '/branding';
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true)) {
            throw new RuntimeException('config.theme.logo_write');
        }
        foreach (glob($dir . '/logo.*') ?: [] as $old) {
            @unlink($old);
        }
        $name = 'logo.' . self::TYPES[$type];
        if (@file_put_contents($dir . '/' . $name, $content) === false) {
            throw new RuntimeException('config.theme.logo_write');
        }

        return $name;
    }

    public function removeLogo(): void
    {
        foreach (glob($this->dataDir . '/branding/logo.*') ?: [] as $old) {
            @unlink($old);
        }
    }

    /**
     * Stylesheet that replaces the accent tokens (inline styles are forbidden by the CSP).
     */
    public static function css(Theme $theme): string
    {
        if ($theme->accent === null) {
            return '';
        }
        $dark = Theme::darkVariant($theme->accent);

        return \sprintf(
            ":root {\n  --color-accent: %s;\n  --color-accent-text: %s;\n}\n\n@media (prefers-color-scheme: dark) {\n  :root {\n    --color-accent: %s;\n    --color-accent-text: %s;\n  }\n}\n",
            $theme->accent,
            Theme::textOn($theme->accent),
            $dark,
            Theme::textOn($dark),
        );
    }

    /**
     * Image type read from the content itself (not from the name or the browser).
     */
    public static function detect(string $content): ?string
    {
        return match (true) {
            str_starts_with($content, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($content, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    public static function mimeType(string $path): string
    {
        $type = array_search(pathinfo($path, PATHINFO_EXTENSION), self::TYPES, true);

        return \is_string($type) ? $type : 'image/jpeg';
    }
}
