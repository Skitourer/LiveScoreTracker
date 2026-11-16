<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * Branding (spec § 12.3): one accent colour and an optional logo, plus how
 * children are named to referees and in the participant area.
 */
final class Theme
{
    /** Contrast needed for normal text (WCAG AA). */
    public const AA = 4.5;

    private const LIGHT_BACKGROUND = '#ffffff';
    private const DARK_BACKGROUND = '#121212';

    public function __construct(
        public readonly ?string $accent = null,
        public readonly ?string $logo = null,
        public readonly NameStyle $refereeNames = NameStyle::Short,
        public readonly NameStyle $participantNames = NameStyle::Short,
    ) {}

    public static function isColor(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /**
     * Text colour on the accent (buttons): white or near black, whichever reads best.
     */
    public static function textOn(string $color): string
    {
        return self::contrast($color, '#ffffff') >= self::contrast($color, '#1a1a1a') ? '#ffffff' : '#1a1a1a';
    }

    /**
     * Whether a colour can be the accent: readable as text on the light
     * background and with its text colour on top of it.
     */
    public static function readable(string $color): bool
    {
        return self::contrast($color, self::LIGHT_BACKGROUND) >= self::AA && self::contrast($color, self::textOn($color)) >= self::AA;
    }

    /**
     * Accent for the dark mode: the same hue lightened until it reads on the dark background.
     */
    public static function darkVariant(string $color): string
    {
        [$r, $g, $b] = self::rgb($color);
        for ($step = 0; $step <= 20; ++$step) {
            $mix = $step / 20;
            $candidate = \sprintf('#%02x%02x%02x', (int) round($r + (255 - $r) * $mix), (int) round($g + (255 - $g) * $mix), (int) round($b + (255 - $b) * $mix));
            if (self::contrast($candidate, self::DARK_BACKGROUND) >= self::AA) {
                return $candidate;
            }
        }

        return '#ffffff';
    }

    /**
     * WCAG contrast ratio between two colours (1 to 21).
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $color): float
    {
        $channels = array_map(static function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($color));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $color): array
    {
        return [(int) hexdec(substr($color, 1, 2)), (int) hexdec(substr($color, 3, 2)), (int) hexdec(substr($color, 5, 2))];
    }
}
