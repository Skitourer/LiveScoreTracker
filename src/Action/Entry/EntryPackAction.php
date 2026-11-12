<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\JsonResponder;
use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Application\EntryPack;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Infrastructure\Help\HelpPages;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * The data a phone keeps to enter results at a workshop offline (spec § 8.8), as JSON,
 * with the workshop's help page so that it can be read without network too.
 */
final class EntryPackAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly EntryDesk $desk,
        private readonly EntryPack $pack,
        private readonly Twig $twig,
        private readonly HelpPages $help,
        private readonly CompetitionConfig $config,
        private readonly Translator $translator,
    ) {}

    /**
     * @param array{workshop: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $workshop = $this->desk->workshop($args['workshop']) ?? throw new HttpNotFoundException($request);
        $context = $this->responder->context;

        $pack = $this->pack->build($workshop, $context->phase->value, $context->session()->csrfToken());
        $pack['help'] = $this->twig->fetch('entry/_help_content.html.twig', [
            'help' => $this->help->html($workshop, $this->translator->locale(), $this->config->edition->defaultLocale),
            'contacts' => $this->config->contacts,
        ]);

        return JsonResponder::write($response, $pack);
    }
}
