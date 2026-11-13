<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\I18n;

/**
 * Languages offered on the public registration pages and in the emails sent
 * to families (spec § 5.1), each named in its own language. The emails always
 * add the French version.
 */
final class PublicLanguages
{
    public const NAMES = [
        'fr' => 'Français',
        'en' => 'English',
        'it' => 'Italiano',
        'de' => 'Deutsch',
        'nl' => 'Nederlands',
        'es' => 'Español',
        'pt' => 'Português',
        'uk' => 'Українська',
        'zh' => '中文',
        'ar' => 'العربية',
    ];

    /** Languages written from right to left. */
    private const RTL = ['ar'];

    public static function supports(string $locale): bool
    {
        return isset(self::NAMES[$locale]);
    }

    public static function direction(string $locale): string
    {
        return \in_array($locale, self::RTL, true) ? 'rtl' : 'ltr';
    }

    /**
     * Best supported language of an Accept-Language header, or null.
     */
    public static function fromAcceptLanguage(string $header): ?string
    {
        $choices = [];
        foreach (explode(',', $header) as $index => $part) {
            $pieces = explode(';', trim($part));
            $code = strtolower(substr(trim($pieces[0]), 0, 2));
            $quality = 1.0;
            foreach (\array_slice($pieces, 1) as $param) {
                if (str_starts_with(trim($param), 'q=')) {
                    $quality = (float) substr(trim($param), 2);
                }
            }
            if (self::supports($code) && $quality > 0) {
                // Stable order for equal qualities: earlier first.
                $choices[] = [$quality, -$index, $code];
            }
        }
        if ($choices === []) {
            return null;
        }
        rsort($choices);

        return $choices[0][2];
    }
}
