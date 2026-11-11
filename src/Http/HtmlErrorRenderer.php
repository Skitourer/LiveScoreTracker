<?php

declare(strict_types=1);

namespace LiveScoreTracker\Http;

use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorRendererInterface;
use Slim\Views\Twig;
use Throwable;

/**
 * Translated HTML error pages (400, 403, 404, 405, 500…).
 * Technical details are only shown when APP_DEBUG is on.
 */
final class HtmlErrorRenderer implements ErrorRendererInterface
{
    public function __construct(private readonly Twig $twig) {}

    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        $reason = $exception instanceof HttpException && $exception->getMessage() === 'csrf' ? 'csrf' : (string) $status;
        if (!\in_array($reason, ['csrf', '400', '403', '404', '405', '429'], true)) {
            $reason = '500';
        }

        try {
            return $this->twig->fetch('error.html.twig', [
                'reason' => $reason,
                'details' => $displayErrorDetails ? $exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() : null,
            ]);
        } catch (Throwable) {
            return '<!doctype html><title>Error</title><h1>Error ' . $status . '</h1>';
        }
    }
}
