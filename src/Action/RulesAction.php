<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Action\Signup\SignupLanguage;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Infrastructure\Help\HelpPages;
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
        private readonly SignupLanguage $language,
        private readonly CompetitionConfig $config,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Same language as the registration pages (menu, browser), translated rules when provided.
        $locale = $this->language->choose($request);
        $rules = $this->pages->page('rules', $locale, $this->config->edition->defaultLocale)
            ?? throw new HttpNotFoundException($request);

        return $this->twig->render($response, 'rules.html.twig', [...SignupLanguage::view($locale), 'rules' => $rules]);
    }
}
