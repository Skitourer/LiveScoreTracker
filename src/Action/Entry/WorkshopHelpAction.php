<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Infrastructure\Help\HelpPages;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Workshop help page (spec § 8.7 and § 12.6), with the contacts to call.
 */
final class WorkshopHelpAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly EntryDesk $desk,
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

        return $this->responder->render($response, 'entry/help.html.twig', [
            'workshop' => $workshop,
            'help' => $this->help->html($workshop, $this->translator->locale(), $this->config->edition->defaultLocale),
            'contacts' => $this->config->contacts,
        ]);
    }
}
