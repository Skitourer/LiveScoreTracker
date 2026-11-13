<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Config;

use Internal\Toml\Toml;
use LiveScoreTracker\Application\ConfigForms;
use LiveScoreTracker\Domain\Config\ConfigDiff;
use PHPUnit\Framework\TestCase;

/**
 * Forms of the configuration screen: showing a section and saving it unchanged changes nothing.
 */
final class ConfigFormsTest extends TestCase
{
    public function testEverySectionRoundTrips(): void
    {
        $data = self::example();
        foreach (['edition', 'participants', 'registration', 'scoring', 'import', 'contacts'] as $tab) {
            $values = ConfigForms::values($tab, $data);
            $form = $tab === 'contacts' ? ['contacts' => $values] : $values;
            self::assertSame([], ConfigDiff::compare($data, ConfigForms::apply($tab, $data, $form)), $tab);
        }
    }

    public function testEveryWorkshopRoundTrips(): void
    {
        $workshops = self::example()['workshops'] ?? [];
        self::assertIsArray($workshops);
        foreach ($workshops as $workshop) {
            self::assertIsArray($workshop);
            $values = ConfigForms::workshopValues($workshop);
            $after = ConfigForms::workshop($workshop, $values);
            self::assertSame([], ConfigDiff::compare($workshop, $after));
        }
    }

    /**
     * @return array<mixed>
     */
    private static function example(): array
    {
        return Toml::parseToArray((string) file_get_contents(\dirname(__DIR__, 3) . '/config.example/competition.toml'));
    }
}
