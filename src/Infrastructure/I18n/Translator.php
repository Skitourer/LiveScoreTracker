<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\I18n;

/**
 * Looks up displayed texts by key (spec § 12.4).
 *
 * Lookup order: the requested locale, then the fallback locale, then the key
 * itself (so a missing text is visible instead of silently empty).
 * Placeholders such as `{first_name}` are replaced by the given parameters.
 */
final class Translator
{
    /**
     * @param array<string, array<string, string>> $catalogs locale => (key => text)
     */
    public function __construct(
        private readonly string $locale,
        private readonly array $catalogs,
        private readonly string $fallbackLocale = 'en',
    ) {}

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * @param array<string, string|int|float> $params
     */
    public function trans(string $key, array $params = []): string
    {
        $text = $this->find($key) ?? $key;

        if ($params === []) {
            return $text;
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replacements);
    }

    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * Translates $text when it is a known key, otherwise returns it unchanged
     * (configuration labels may be keys or plain texts, spec § 7.2).
     */
    public function label(string $text): string
    {
        return $this->find($text) ?? $text;
    }

    private function find(string $key): ?string
    {
        return $this->catalogs[$this->locale][$key] ?? $this->catalogs[$this->fallbackLocale][$key] ?? null;
    }
}
