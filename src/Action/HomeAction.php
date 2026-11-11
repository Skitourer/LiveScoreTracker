<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Domain\Config\InvalidConfig;
use LiveScoreTracker\Infrastructure\BuildInfo;
use LiveScoreTracker\Infrastructure\Config\ConfigRepository;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Home page. For now it shows the system status and, outside production,
 * a link to the scoring report on fictitious data.
 */
final class HomeAction
{
    public function __construct(
        private readonly Twig $twig,
        private readonly SystemStatus $status,
        private readonly BuildInfo $build,
        private readonly Settings $settings,
        private readonly ConfigRepository $configRepository,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->twig->render($response, 'home.html.twig', [
            'build' => $this->build,
            'environment' => $this->settings->environment,
            'status' => $this->status->check(),
            'config' => $this->configSummary(),
            'showReport' => !$this->settings->isProduction(),
        ]);
    }

    /**
     * @return array{valid: bool, example: bool, events: int, categories: int}
     */
    private function configSummary(): array
    {
        try {
            $config = $this->configRepository->get();

            return [
                'valid' => true,
                'example' => $this->configRepository->usesExample(),
                'events' => \count($config->variants()),
                'categories' => \count($config->categories()),
            ];
        } catch (InvalidConfig) {
            return ['valid' => false, 'example' => $this->configRepository->usesExample(), 'events' => 0, 'categories' => 0];
        }
    }
}
