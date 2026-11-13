<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\I18n;

/**
 * Looks up displayed texts by key (spec § 12.4).
 *
 * Lookup order: the requested locale, then the fallback locale, then the key
 * itself (so a missing text is visible instead of silently empty).
 * Placeholders such as `{first_name}` are replaced by the given parameters.
 *
 * The public registration pages and the emails sent to families may use
 * another language than the installation's (spec § 5.1): catalogs of other
 * locales are then loaded on demand.
 */
final class Translator
{
    /** @var array<string, string> */
    private array $overrides = [];

    /** @var (\Closure(string): array<string, string>)|null */
    private readonly ?\Closure $loader;

    /**
     * @param array<string, array<string, string>>         $catalogs locale => (key => text)
     * @param (callable(string): array<string, string>)|null $loader   loads the catalog of another locale
     */
    public function __construct(
        private string $locale,
        private array $catalogs,
        private readonly string $fallbackLocale = 'en',
        ?callable $loader = null,
    ) {
        $this->loader = $loader === null ? null : $loader(...);
    }

    /**
     * Switches the language of the texts for the rest of the request (public registration pages).
     */
    public function useLocale(string $locale): void
    {
        $this->ensure($locale);
        $this->locale = $locale;
    }

    /**
     * Text in a given language, whatever the current one (emails to families).
     *
     * @param array<string, string|int|float> $params
     */
    public function transIn(string $locale, string $key, array $params = []): string
    {
        $current = $this->locale;
        $this->useLocale($locale);
        try {
            return $this->trans($key, $params);
        } finally {
            $this->locale = $current;
        }
    }

    public function labelIn(string $locale, string $text): string
    {
        $current = $this->locale;
        $this->useLocale($locale);
        try {
            return $this->label($text);
        } finally {
            $this->locale = $current;
        }
    }

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

    private function ensure(string $locale): void
    {
        if (!isset($this->catalogs[$locale]) && $this->loader !== null) {
            $this->catalogs[$locale] = ($this->loader)($locale);
        }
    }

    /**
     * Texts that override every catalog, whatever the language (names given
     * in the configuration, e.g. a workshop added from the admin screen).
     *
     * @param array<string, string> $texts key => text
     */
    public function override(array $texts): void
    {
        $this->overrides = $texts + $this->overrides;
    }

    private function find(string $key): ?string
    {
        return $this->overrides[$key] ?? $this->catalogs[$this->locale][$key] ?? $this->catalogs[$this->fallbackLocale][$key] ?? null;
    }
}
