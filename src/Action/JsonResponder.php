<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use Psr\Http\Message\ResponseInterface;

/**
 * Writes a JSON body with the right headers.
 */
final class JsonResponder
{
    /**
     * @param array<string, mixed> $data
     */
    public static function write(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
