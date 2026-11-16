<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Infrastructure\Config\Branding;
use LiveScoreTracker\Infrastructure\Help\MediaStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Public branding files (spec § 12.3): accent stylesheet, logo and the
 * images of the help, home and rules pages.
 */
final class BrandingAction
{
    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly Branding $branding,
        private readonly MediaStore $media,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $what = $args['what'] ?? '';
        if ($what === 'theme.css') {
            $response->getBody()->write(Branding::css($this->config->theme));

            return $response->withHeader('Content-Type', 'text/css; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=300');
        }
        $path = $what === 'logo' ? $this->branding->logoPath($this->config->theme) : $this->media->path($args['file'] ?? '');
        $content = $path === null ? false : file_get_contents($path);
        if ($content === false) {
            throw new HttpNotFoundException($request);
        }
        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', Branding::mimeType($path))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
