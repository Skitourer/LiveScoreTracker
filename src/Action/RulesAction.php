<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Infrastructure\Help\HelpPages;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Rules of the competition (spec § 13.0), a Markdown page of the
 * installation, linked from the home page and the registration form.
 */
final class RulesAction
{
    public function __construct(
        private readonly Twig $twig,
        private readonly HelpPages $pages,
        private readonly Translator $translator,
        private readonly CompetitionConfig $config,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rules = $this->pages->page('rules', $this->translator->locale(), $this->config->edition->defaultLocale)
            ?? throw new HttpNotFoundException($request);

        return $this->twig->render($response, 'rules.html.twig', ['rules' => $rules]);
    }
}
