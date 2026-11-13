<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\I18n;

use Internal\Toml\Toml;
use RuntimeException;
use Throwable;

/**
 * Reads translation catalogs from `i18n/<locale>.toml` files.
 *
 * Generic texts ship with the code; the private data folder may override any
 * key (spec § 12.1). Nested tables become dotted keys:
 * `[workshop.sprint] name = "Sprint"` gives `workshop.sprint.name`.
 */
final class CatalogLoader
{
    /**
     * @param list<string> $directories searched in order; later ones override earlier ones
     */
    public function __construct(private readonly array $directories) {}

    /**
     * @param list<string> $locales
     */
    public function translator(string $locale, array $locales = ['fr', 'en'], string $fallbackLocale = 'en'): Translator
    {
        $catalogs = [];
        foreach (array_unique([...$locales, $locale, $fallbackLocale]) as $code) {
            $catalogs[$code] = $this->load($code);
        }

        return new Translator($locale, $catalogs, $fallbackLocale, $this->load(...));
    }

    /**
     * @return array<string, string>
     */
    public function load(string $locale): array
    {
        if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale) !== 1) {
            throw new RuntimeException(\sprintf('Invalid locale "%s".', $locale));
        }

        $messages = [];
        foreach ($this->directories as $directory) {
            $file = rtrim($directory, '/') . '/' . $locale . '.toml';
            if (!is_file($file)) {
                continue;
            }
            try {
                $data = Toml::parseToArray((string) file_get_contents($file));
            } catch (Throwable $exception) {
                throw new RuntimeException(\sprintf('Translation file %s is invalid: %s', $file, $exception->getMessage()), 0, $exception);
            }
            $messages = array_replace($messages, self::flatten($data));
        }

        return $messages;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (\is_array($value)) {
                $flat += self::flatten($value, $name);
            } elseif (\is_scalar($value)) {
                $flat[$name] = (string) $value;
            }
        }

        return $flat;
    }
}
