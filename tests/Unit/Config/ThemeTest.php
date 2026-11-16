<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Config;

use LiveScoreTracker\Domain\Config\NameStyle;
use LiveScoreTracker\Domain\Config\Theme;
use LiveScoreTracker\Infrastructure\Config\Branding;
use PHPUnit\Framework\TestCase;

/**
 * Step 12c: accent colour checks and the computed dark variant (spec § 12.3).
 */
final class ThemeTest extends TestCase
{
    public function testContrastFollowsWcag(): void
    {
        self::assertEqualsWithDelta(21.0, Theme::contrast('#000000', '#ffffff'), 0.01);
        self::assertEqualsWithDelta(1.0, Theme::contrast('#777777', '#777777'), 0.01);
    }

    public function testOnlyReadableAccentsAreAccepted(): void
    {
        self::assertTrue(Theme::readable('#1f5fbf'));
        self::assertTrue(Theme::readable('#8a1538'));
        self::assertFalse(Theme::readable('#ffd700'), 'Yellow cannot be read on white.');
        self::assertFalse(Theme::isColor('blue'));
    }

    public function testTheDarkVariantReadsOnTheDarkBackground(): void
    {
        $dark = Theme::darkVariant('#1f5fbf');
        self::assertGreaterThanOrEqual(Theme::AA, Theme::contrast($dark, '#121212'));

        $css = Branding::css(new Theme('#8a1538'));
        self::assertStringContainsString('--color-accent: #8a1538;', $css);
        self::assertStringContainsString('prefers-color-scheme: dark', $css);
        self::assertSame('', Branding::css(new Theme()));
    }

    public function testImageTypesAreReadFromTheContent(): void
    {
        self::assertSame('image/png', Branding::detect("\x89PNG\r\n\x1a\nrest"));
        self::assertSame('image/webp', Branding::detect('RIFF0000WEBPVP8 '));
        self::assertNull(Branding::detect('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
        self::assertSame(NameStyle::Short, (new Theme())->refereeNames);
    }
}
