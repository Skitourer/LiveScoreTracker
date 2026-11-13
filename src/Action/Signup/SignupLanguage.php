<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Signup;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Http\RequestContext;
use LiveScoreTracker\Infrastructure\I18n\PublicLanguages;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Language of the public registration pages (spec § 5.1): chosen in the menu
 * (`?lang=xx`, remembered for the session), else the browser's language when
 * offered, else the installation's language.
 */
final class SignupLanguage
{
    private const SESSION_KEY = 'signup_lang';

    public function __construct(
        private readonly Translator $translator,
        private readonly RequestContext $context,
        private readonly CompetitionConfig $config,
    ) {}

    public function choose(ServerRequestInterface $request): string
    {
        $session = $this->context->session();
        $asked = Responder::query($request, 'lang');
        if (PublicLanguages::supports($asked)) {
            $session->set(self::SESSION_KEY, $asked);
        }
        $remembered = $session->get(self::SESSION_KEY);
        $locale = \is_string($remembered) && PublicLanguages::supports($remembered)
            ? $remembered
            : PublicLanguages::fromAcceptLanguage($request->getHeaderLine('Accept-Language')) ?? $this->config->edition->defaultLocale;

        return $this->use($locale);
    }

    /**
     * Switches the pages to a given language (the one stored with a registration).
     */
    public function use(string $locale): string
    {
        $locale = PublicLanguages::supports($locale) ? $locale : $this->config->edition->defaultLocale;
        $this->translator->useLocale($locale);

        return $locale;
    }

    /**
     * Template variables: page language and direction, and the language menu.
     *
     * @return array{lang: string, dir: string, languages: array<string, string>}
     */
    public static function view(string $locale): array
    {
        return ['lang' => $locale, 'dir' => PublicLanguages::direction($locale), 'languages' => PublicLanguages::NAMES];
    }
}
