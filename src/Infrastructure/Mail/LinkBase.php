<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Mail;

/**
 * Scheme and host of the links put in emails: APP_URL, or the current request,
 * always in https except on a local development host (spec § 20.1). Links in
 * plain http are one of the signals that get emails blocked as spam.
 */
final class LinkBase
{
    public static function of(string $appUrl, string $requestBase): string
    {
        $base = rtrim($appUrl !== '' ? $appUrl : $requestBase, '/');
        if (stripos($base, 'http://') === 0 && !self::isLocal((string) parse_url($base, PHP_URL_HOST))) {
            return 'https://' . substr($base, 7);
        }

        return $base;
    }

    public static function isLocal(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return \in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
    }
}
