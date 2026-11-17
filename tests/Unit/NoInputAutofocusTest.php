<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Issue #33: never move the focus into a form field on its own. On phones it
 * opens the keyboard and hides half the screen. Messages may still take focus.
 */
final class NoInputAutofocusTest extends TestCase
{
    public function testNoFieldIsFocusedAutomatically(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['templates', 'public/assets/js'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($files as $file) {
                if (!$file->isFile() || !preg_match('/\.(twig|js)$/', $file->getFilename())) {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                // An autofocus attribute on a field element.
                if (preg_match('/<(input|select|textarea)\b[^>]*\bautofocus\b/is', $code)) {
                    $offenders[] = $file->getPathname();
                }
                // A script focusing a field.
                if (preg_match('/(input|select|textarea|-input)[^;\n]*\.focus\(/i', $code)) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        self::assertSame([], $offenders);
    }
}
