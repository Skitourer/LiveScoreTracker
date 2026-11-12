<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit;

use LiveScoreTracker\Application\EntryPack;
use LiveScoreTracker\Infrastructure\I18n\CatalogLoader;
use PHPUnit\Framework\TestCase;

/**
 * Every text sent to the phones for offline entry exists in each language.
 */
final class EntryPackTextsTest extends TestCase
{
    public function testTextsExistInEveryLanguage(): void
    {
        $keys = (new \ReflectionClassConstant(EntryPack::class, 'TEXTS'))->getValue();
        self::assertIsArray($keys);
        $loader = new CatalogLoader([\dirname(__DIR__, 2) . '/config.example/i18n']);
        foreach (['fr', 'en'] as $locale) {
            $catalog = $loader->load($locale);
            foreach ($keys as $key) {
                self::assertIsString($key);
                self::assertArrayHasKey($key, $catalog, $locale);
            }
        }
    }
}
