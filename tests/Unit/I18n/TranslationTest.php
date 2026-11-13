<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\I18n;

use LiveScoreTracker\Domain\Config\Better;
use LiveScoreTracker\Domain\Config\FoulMode;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\ScoringRules;
use LiveScoreTracker\Domain\Config\TimeFormat;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Infrastructure\I18n\CatalogLoader;
use LiveScoreTracker\Infrastructure\I18n\PublicLanguages;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Presentation\ResultFormatter;
use PHPUnit\Framework\TestCase;

final class TranslationTest extends TestCase
{
    private const GENERIC = __DIR__ . '/../../../config.example/i18n';

    public function testFrenchAndEnglishCatalogsHaveTheSameKeys(): void
    {
        $loader = new CatalogLoader([self::GENERIC]);
        $fr = array_keys($loader->load('fr'));
        $en = array_keys($loader->load('en'));
        sort($fr);
        sort($en);

        self::assertSame($fr, $en);
        self::assertNotEmpty($fr);
    }

    public function testEachPublicLanguageTranslatesTheRegistrationPagesAndEmails(): void
    {
        $loader = new CatalogLoader([self::GENERIC]);
        $fr = $loader->load('fr');
        $public = array_filter(
            $fr,
            static fn(string $key): bool => preg_match('/^(signup\\.|email\\.(signup_|registration_confirmed\\.)|registration\\.(required|field\\.(last_name|first_name|birth_date|gender|level|school|guardian_))|public\\.state\\.)/', $key) === 1,
            ARRAY_FILTER_USE_KEY,
        );
        self::assertGreaterThan(60, \count($public));

        foreach (array_keys(PublicLanguages::NAMES) as $locale) {
            $catalog = $loader->load($locale);
            foreach ($public as $key => $text) {
                self::assertArrayHasKey($key, $catalog, "{$locale}: {$key}");
                preg_match_all('/\\{[a-z_]+\\}/', $text, $expected);
                preg_match_all('/\\{[a-z_]+\\}/', $catalog[$key], $found);
                $expectedNames = array_unique($expected[0]);
                $foundNames = array_unique($found[0]);
                sort($expectedNames);
                sort($foundNames);
                self::assertSame($expectedNames, $foundNames, "{$locale}: placeholders of {$key}");
            }
        }
    }

    public function testPicksTheBrowserLanguageWhenOffered(): void
    {
        self::assertSame('de', PublicLanguages::fromAcceptLanguage('de-CH,de;q=0.9,en;q=0.8'));
        self::assertSame('uk', PublicLanguages::fromAcceptLanguage('ru;q=0.9, uk'));
        self::assertNull(PublicLanguages::fromAcceptLanguage('ja,ko'));
        self::assertSame('rtl', PublicLanguages::direction('ar'));
        self::assertSame('ltr', PublicLanguages::direction('zh'));
    }

    public function testTextsOfAnotherLanguageAreLoadedOnDemand(): void
    {
        $translator = (new CatalogLoader([self::GENERIC]))->translator('fr');
        self::assertSame('Ein Kind anmelden', $translator->transIn('de', 'signup.title'));
        self::assertSame('Inscrire un enfant', $translator->trans('signup.title'), 'Current language unchanged');
        $translator->useLocale('it');
        self::assertSame('Iscrivere un bambino', $translator->trans('signup.title'));
        self::assertSame('Log out', $translator->trans('nav.logout'), 'Missing texts fall back to English');
    }

    public function testReplacesPlaceholdersAndFallsBack(): void
    {
        $translator = new Translator('fr', [
            'fr' => ['hello' => 'Bonjour {name}'],
            'en' => ['hello' => 'Hello {name}', 'only_en' => 'English'],
        ]);

        self::assertSame('Bonjour Zoé', $translator->trans('hello', ['name' => 'Zoé']));
        self::assertSame('English', $translator->trans('only_en'));
        self::assertSame('missing.key', $translator->trans('missing.key'));
        self::assertSame('30 m', $translator->label('30 m'));
        self::assertSame('Bonjour {name}', $translator->label('hello'));
    }

    public function testDataFolderOverridesGenericTexts(): void
    {
        $dir = sys_get_temp_dir() . '/lst-i18n-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/fr.toml', "[app]\nname = \"Jeux fictifs\"\n");

        try {
            $translator = (new CatalogLoader([self::GENERIC, $dir]))->translator('fr');
            self::assertSame('Jeux fictifs', $translator->trans('app.name'));
            self::assertSame('Sprint', $translator->trans('workshop.sprint.name'));
        } finally {
            unlink($dir . '/fr.toml');
            rmdir($dir);
        }
    }

    public function testFormatsResultsWithUnits(): void
    {
        $fr = new ResultFormatter((new CatalogLoader([self::GENERIC]))->translator('fr'));
        $en = new ResultFormatter((new CatalogLoader([self::GENERIC]))->translator('en'));

        self::assertSame('12,25 s', $fr->format(1225, self::variant(Measure::Time, TimeFormat::Seconds)));
        self::assertSame('12.25 s', $en->format(1225, self::variant(Measure::Time, TimeFormat::Seconds)));
        self::assertSame('1 min 23,24 s', $fr->format(8324, self::variant(Measure::Time, TimeFormat::Minutes)));
        self::assertSame('1 min 03,05 s', $fr->format(6305, self::variant(Measure::Time, TimeFormat::Minutes)));
        self::assertSame('45,12 s', $fr->format(4512, self::variant(Measure::Time, TimeFormat::Minutes)));
        self::assertSame('12,34 m', $fr->format(1234, self::variant(Measure::Distance)));
        self::assertSame('0,50 m', $fr->format(50, self::variant(Measure::Distance)));
        self::assertSame('22 pts', $fr->format(22, self::variant(Measure::Counter)));
    }

    private static function variant(Measure $measure, ?TimeFormat $format = null): Variant
    {
        return new Variant('w', 'g', $measure, Better::Lower, 1, FoulMode::Strict, new ScoringRules(), timeFormat: $format);
    }
}
