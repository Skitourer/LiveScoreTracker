<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Application\RegistrationWindow;
use LiveScoreTracker\Domain\Config\InvalidConfig;
use LiveScoreTracker\Infrastructure\Config\ConfigRepository;
use LiveScoreTracker\Infrastructure\Help\HelpPages;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Throwable;

/**
 * Public home page (spec § 13.0): the edition (date, times, venue,
 * organizer, contact), a presentation text, the events of each level group,
 * where registration stands with the places left, and links to the rules and
 * to the volunteers' login. No personal data. Indexed by search engines in
 * production only.
 */
final class HomeAction
{
    public function __construct(
        private readonly Twig $twig,
        private readonly Settings $settings,
        private readonly ConfigRepository $configRepository,
        private readonly HelpPages $pages,
        private readonly Translator $translator,
        private readonly AppState $appState,
        private readonly RegistrationWindow $window,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $config = $this->configRepository->get();
        } catch (InvalidConfig) {
            // Nothing to show yet: the status page explains what is wrong.
            return $this->twig->render($response, 'home.html.twig', ['config' => null]);
        }

        $registration = null;
        $phase = 'preparation';
        try {
            $phase = $this->appState->phase()->value;
            $registration = $this->window->status($config);
        } catch (Throwable) {
            // Database not ready (first deployment): the edition is shown without registration.
        }

        $groups = [];
        foreach ($config->levelGroups as $group => $levels) {
            $groups[] = ['levels' => $levels, 'variants' => $config->variantsForGroup((string) $group)];
        }

        return $this->twig->render($response, 'home.html.twig', [
            'config' => $config,
            'edition' => $config->edition,
            'presentation' => $this->pages->page('home', $this->translator->locale(), $config->edition->defaultLocale),
            'has_rules' => $this->pages->page('rules', $this->translator->locale(), $config->edition->defaultLocale) !== null,
            'groups' => $groups,
            'registration' => $registration,
            'phase' => $phase,
            'indexable' => $this->settings->isProduction(),
        ]);
    }
}
